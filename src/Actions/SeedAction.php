<?php

namespace Nnjeim\World\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Nnjeim\World\Models;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

class SeedAction extends Seeder
{
    protected SchemaBuilder $schema;

    private array $countries = [
        'data' => [],
    ];

    private array $modules = [
        'states' => [
            'data' => [],
            'enabled' => false,
        ],
        'cities' => [
            'data' => [],
            'enabled' => false,
        ],
        'timezones' => [
            'enabled' => false,
        ],
        'currencies' => [
            'data' => [],
            'enabled' => false,
        ],
        'languages' => [
            'data' => [],
            'enabled' => false,
        ],
    ];

    public function __construct()
    {
        foreach ($this->modules as $name => $data) {
            $this->modules[$name]['class'] = config('world.models.' . $name);
        }

        $this->schema = Schema::connection(config('world.connection'));

        // countries
        $this->initCountries();

        // init modules
        foreach (config('world.modules') as $module => $enabled) {
            if ($enabled) {
                $this->modules[$module]['enabled'] = true;
                $this->initModule($module);
            }
        }
    }

    public function run(): void
    {
        $this->command->getOutput()->block('Seeding start');
        $this->command->getOutput()->progressStart(count($this->countries['data']));

        // $this->forgetFields($countryFields, ['id']);
        $this->changeFieldName($this->countries['data'], ["id" => "country_id"]);
        $countryFields = array_keys($this->countries['data'][0]);

        foreach (array_chunk($this->countries['data'], 20) as $countryChunks) {

            foreach ($countryChunks as $countryArray) {

                $countryArray = array_map(fn($field) => gettype($field) === 'string' ? trim($field) : $field, $countryArray);

                $countryClass = config('world.models.countries');
                $country = $countryClass::create(Arr::only($countryArray, $countryFields)); // Create MongoDB country instance

                // states and cities
                if ($this->isModuleEnabled('states')) {
                    $this->seedStates($country, $countryArray);
                }

                // timezones
                if ($this->isModuleEnabled('timezones')) {
                    $this->seedTimezones($country, $countryArray);
                }

                // currencies
                if ($this->isModuleEnabled('currencies')) {
                    $this->seedCurrencies($country, $countryArray);
                }

                $this->command->getOutput()->progressAdvance();
            }
        }

        // languages
        if ($this->isModuleEnabled('languages')) {
            $this->seedLanguages();
        }

        $this->command->getOutput()->progressFinish();

        $this->command->getOutput()->block('Seeding end');
    }

    private function initModule(string $module)
    {
        if (array_key_exists($module, $this->modules)) {
            // Import JSON data
            $moduleSourcePath = __DIR__ . '/../../resources/json/' . $module . '.json';

            if (File::exists($moduleSourcePath)) {
                $this->modules[$module]['data'] = json_decode(File::get($moduleSourcePath), true);
            }
        }
    }

    private function isModuleEnabled(string $module): bool
    {
        return $this->modules[$module]['enabled'];
    }

    private function initCountries(): void
    {
        $countryClass = config('world.models.countries');
		app($countryClass)->truncate();

        $this->countries['data'] = json_decode(File::get(__DIR__ . '/../../resources/json/countries.json'), true);

        if (!empty(config('world.allowed_countries')))
            $this->countries['data'] = Arr::where($this->countries['data'], function ($value, $key) {
                return in_array($value['iso2'], config('world.allowed_countries'));
            });

        if (!empty(config('world.disallowed_countries')))
            $this->countries['data'] = Arr::where($this->countries['data'], function ($value, $key) {
                return !in_array($value['iso2'], config('world.disallowed_countries'));
            });
    }

    private function seedLanguages(): void
    {
        $languageClass = config('world.models.languages');
        $languageClass::insert($this->modules['languages']['data']); // Bulk insert MongoDB
    }

    private function forgetFields(array &$array, array $values)
    {
        foreach ($values as $value) {
            if (($key = array_search($value, $array)) !== false) {
                unset($array[$key]);
            }
        }
    }

	private function changeFieldName(array &$array, array $values) {
        foreach($array as $key => $item){
            foreach($values as $old_key => $new_key){
                $item[$new_key] = $item[$old_key];
                unset($item[$old_key]);
            }

            $array[$key] = $item;
        }
    }

	private function seedStates(Models\Country $country, array $countryArray): void
	{
		$countryStates = Arr::where($this->modules['states']['data'], fn($state) => $state['country_id'] === $countryArray['country_id']);

        if($countryStates){
            $this->changeFieldName($countryStates, ['id' => "state_id"]);
            $stateFields = array_keys(Arr::first($countryStates));
            $bulk_states = [];

            $this->forgetFields($stateFields, ['country_id']);

            foreach ($countryStates as $stateArray) {
                $stateArray = array_map(fn($field) => gettype($field) === 'string' ? trim($field) : $field, $stateArray);
                
                $bulk_states[] = Arr::add(
                    Arr::only($stateArray, $stateFields),
                    'country_id',
                    $country->id  // Accessing as a property instead of array
                );
            }

            Models\State::insert($bulk_states); // Bulk insert MongoDB

            // If cities module is enabled
            if ($this->isModuleEnabled('cities')) {
                $stateNames = array_column($bulk_states, 'name');

                $stateCities = Arr::where(
                    $this->modules['cities']['data'],
                    fn($city) => $city['country_id'] === $countryArray['country_id'] && in_array($city['state_name'], $stateNames, true)
                );

                if($stateCities)
                    $this->seedCities($country, $bulk_states, $stateCities);
            }
        }
	}

	/**
	 * @param Models\Country $country
	 * @param array $states
	 * @param array $cities
	 */
	private function seedCities(Models\Country $country, array $states, array $cities): void
	{
		//using array_chunk to prevent mySQL too many placeholders error
		foreach (array_chunk($cities, 500) as $cityChunks) {
			$cities_bulk = [];
			foreach ($cityChunks as $cityArray) {
				$cityArray = array_map(fn($field) => gettype($field) === 'string' ? trim($field) : $field, $cityArray);

                if(!isset($cityFields)){
                    $cityFields = array_keys($cityArray);
                    $this->forgetFields($cityFields, ["id"]);
                }

				$city = Arr::only($cityArray, $cityFields);

				$state = Arr::first($states, fn($state) => $state['name'] === $cityArray['state_name']);

				$city = Arr::add(
					$city,
					'state_id',
					$state['state_id']
				);

				$city = Arr::add(
					$city,
					'country_id',
					$country->id
				);

				$cities_bulk[] = $city;
			}

			$cityClass = config('world.models.cities');
			$cityClass::insert($cities_bulk);
		}
	}

	private function seedTimezones(Models\Country $country, $countryArray): void
	{
		$bulk_timezones = [];

		foreach ($countryArray['timezones'] as $timezone) {
			$bulk_timezones[] = [
				'country_id' => $country->id, // Accessing country id properly
				'name' => (string)$timezone['zoneName'],
			];
		}

		Models\Timezone::insert($bulk_timezones); // Bulk insert MongoDB
	}

	private function seedCurrencies(Models\Country $country, array $countryArray): void
	{
		$exists = in_array($countryArray['currency'], array_keys($this->modules['currencies']['data']), true);
		$currency = $exists
			? $this->modules['currencies']['data'][$countryArray['currency']]
			: [
				'name' => (string)$countryArray['currency'],
				'code' => (string)$countryArray['currency'],
				'symbol' => (string)$countryArray['currency_symbol'],
				'symbol_native' => (string)$countryArray['currency_symbol'],
				'decimal_digits' => 2,
			];

		$country
			->currency()
			->create([  // MongoDB insertion
				'name' => (string)$currency['name'],
				'code' => (string)$currency['code'],
				'symbol' => (string)$currency['symbol'],
				'symbol_native' => (string)$currency['symbol_native'],
				'precision' => (int)$currency['decimal_digits'],
			]);
	}
}
