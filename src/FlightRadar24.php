<?php

/**
 * This file is part of jawish/flightradar24.
 *
 * (c) Jawish Hameed <jawish@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jawish\FlightRadar24;

/**
 * FlightRadar24 API library
 *
 * @package   org.jawish.flightradar24
 * @author    Jawish Hameed <jawish@gmail.com>
 * @copyright 2014 Jawish Hameed
 * @license   http://www.opensource.org/licenses/MIT The MIT License
 */
class FlightRadar24
{
    public static $apiBaseUrl = 'https://www.flightradar24.com';

    const PATH_LOAD_BALANCER = '/balance.json';
    const PATH_AIRPORTS = '/_json/airports.php';
    const PATH_AIRLINES = '/_json/airlines.php';
    const PATH_ZONES = '/js/zones.js.php';
    const PATH_ZONE_AIRCRAFTS = '/zones/fcgi/%s_all.json';
    const PATH_ALL_AIRCRAFTS = '/zones/fcgi/full_all.json';
    const PATH_AIRCRAFT_DETAILS = '/_external/planedata_json.1.3.php?f=%s';

    protected $loadBalancers = [];
    protected $selectedLoadBalancer = null;
    protected $airports = [];
    protected $airlines = [];
    protected $zones = [];
    protected $selectedZone = null;
    protected $aircrafts = [];

    /**
     * Construct object
     *
     * @param string|index      $loadBalancer   Load balancer to use. Can be hostname, index, 'latency', 'random'
     * @param string            $zoneName       Zone to use.
     */ 
    public function __construct($loadBalancer = null, $zoneName = null)
    {
        if ($loadBalancer) {
            $this->selectLoadBalancer($loadBalancer);
        }

        if ($zoneName) {
            $this->selectZone($zoneName);
        }
    }

    /**
     * Fetches and returns the load balancers for aircraft API calls
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Load balancers
     *
     * @throws \Exception        If error while fetching or processing the API request
     */
    public function getLoadBalancers($refresh = false)
    {
        if (empty($this->loadBalancers) || TRUE == $refresh) {

            try {
                $this->loadBalancers = array_keys(
                    $this->api(self::$apiBaseUrl . self::PATH_LOAD_BALANCER)
                );
            }
            catch (\Exception $e) {
                throw new \Exception('An error occurred while fetching and parsing load balancers.');
            }
        }

        return $this->loadBalancers;
    }

    /**
     * Make the load balancer at the given index as the default.
     *
     * @param   string|integer      $lb         Load balancer to use. Can be hostname, index, 'latency', 'random'
     *
     * @return  object
     *
     * @throws \InvalidArgumentException        If the specified index did not exist
     */
    public function selectLoadBalancer($lb = 0)
    {
        // Get the list of load balancers from API
        $loadBalancers = $this->getLoadBalancers();

        // Clear the current selected load balancer index
        $this->selectedLoadBalancer = null;

        // Check load balancer argument type
        if (($index = array_search($lb, $this->loadBalancers)) !== false) {
            // Hostname mode

            $this->selectedLoadBalancer = $index;
        }
        elseif (is_numeric($lb)) {
            // Index mode

            // Use the index if is a valid index
            if (isset($this->loadBalancers[$lb])) {
                $this->selectedLoadBalancer = $lb;
            }
        }
        elseif ($lb == 'latency') {
            // Least latency host mode

            // Time connection attempts to each load balancer host
            $latencies = array_map(
                function($item) {
                    try {
                        $timerStart = microtime(true);

                        $fp = fsockopen($item, 80);
                        if ($fp && fclose($fp)) {
                            return microtime(true) - $timerStart;
                        }
                    }
                    catch (\Exception $e) {
                        // Ignore error
                    }
                },
                $this->loadBalancers
            );

            // Assign the load balancer host with the least latency
            $this->selectedLoadBalancer = array_search(min($latencies), $latencies);
        }
        elseif ($lb == 'random') {
            // Random mode

            $this->selectedLoadBalancer = array_rand($this->loadBalancers, 1);
        }
        

        if (is_null($this->selectedLoadBalancer)) {
            // No valid load balancer specification found, fail

            $this->selectedLoadBalancer = null;
            throw new \InvalidArgumentException(sprintf('Load balancer %d is invalid.', $lb));
        }

        return $this;
    }

    /**
     * Get the selected load balancer.
     *
     * @return array            An array with keys index and host
     */
    public function getSelectedLoadBalancer()
    {
        return array(
            'index' => $this->selectedLoadBalancer, 
            'host' => $this->loadBalancers[$this->selectedLoadBalancer]
        );
    }

    /**
     * Fetch and return the list of airports.
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of array of airports
     *
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getAirports($refresh = false)
    {
        if (empty($this->airports) || true == $refresh) {

            try {
                $this->airports = $this->api(self::$apiBaseUrl . self::PATH_AIRPORTS)['rows'];
            }
            catch (\Exception $e) {
                throw new \Exception('An error occurred while fetching and parsing airports.');
            }
        }

        return $this->airports;
    }

    /**
     * Fetch and return the list of airlines.
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of array of airlines
     *
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getAirlines($refresh = false)
    {
        if (empty($this->airlines) || true == $refresh) {

            try {
                $this->airlines = $this->api(self::$apiBaseUrl . self::PATH_AIRLINES)['rows'];
            }
            catch (\Exception $e) {
                throw new \Exception('An error occurred while fetching and parsing airlines.');
            }
        }

        return $this->airlines;
    }

    /**
     * Fetch and return the list of zones.
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of array of zones
     *
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getZones($refresh = false)
    {
        if (empty($this->zones) || true == $refresh) {

            try {
                $this->zones = $this->api(self::$apiBaseUrl . self::PATH_ZONES);
                unset($this->zones['version']);

                $this->zoneNames = [];
            }
            catch (\Exception $e) {
                throw new \Exception('An error occurred while fetching and parsing zones.');
            }
        }

        return $this->zones;
    }

    /**
     * Return the list of zonenames.
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of zonenames
     *
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getZoneNames($refresh = false)
    {
        if (empty($this->zoneNames) || true == $refresh) {
            $zones = $this->getZones($refresh);
            $this->zoneNames = $this->buildZoneNames($zones);
        }

        return $this->zoneNames;
    }

    /**
     * Extracts and builds a list of the zone names from the API
     * Used internally, by the getZoneNames() function
     *
     * @param array             $zones          Array of zones
     *
     * @return array                            Array of zonenames
     */
    private function buildZoneNames(array &$zones = array())
    {
        $zoneNames = [];

        foreach ($zones as $key => $value) {
            if (!in_array($key, [ 'tl_x', 'tl_y', 'br_x', 'br_y', 'subzones' ])) {
                $zoneNames[] = $key;

                if (isset($value['subzones']) && !empty($value['subzones'])) {
                    $zoneNames = array_merge($zoneNames, $this->buildZoneNames($value['subzones']));
                }
            }
        };

        return $zoneNames;
    }

    /**
     * Select and use the specified zone in future API calls
     *
     * @param string            $zoneName       String zonename to use
     *
     * @return object
     */
    public function selectZone($zoneName)
    {
        $zoneName = strtolower($zoneName);

        $this->selectedZone = in_array($zoneName, $this->getZoneNames()) ? $zoneName : null;

        return $this;
    }

    /**
     * Returns the currently selected zonename
     *
     * @return string                           String currently selected zone name
     */
    public function getSelectedZone()
    {
        return $this->selectedZone;
    }

    /**
     * Fetch and return the list of aircrafts, in the currently selected zone
     *
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of array list of aircrafts as associative array
     *
     * @throws \InvalidArgumentException        If there is no loadbalancer or zone selected
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getAircrafts($refresh = false)
    {
        $loadBalancer = $this->getSelectedLoadBalancer();
        if (is_null($loadBalancer)) {
            throw new \InvalidArgumentException('Load balancer not selected.');
        }

        $zoneName = $this->getSelectedZone();
        if (is_null($zoneName)) {
            throw new \InvalidArgumentException('Zone not selected.');
        }
        
        if (empty($this->aircrafts) || true == $refresh) {
            try {
                $apiPath = ('all' == $zoneName) ? self::PATH_ALL_AIRCRAFTS : self::PATH_ZONE_AIRCRAFTS;
                
                $this->aircrafts = $this->api(
                    sprintf('http://' . $loadBalancer['host'] . $apiPath, $zoneName)
                );

                foreach ($this->aircrafts as $id => $data) {
                    if ($id != 'version' && $id != 'full_count') {

                        $this->aircrafts[$id] = array_combine(
                            [ 'aircraft_id', 'latitude', 'longitude', 'track', 'altitude', 'speed', 'swquawk', 'radar_id', 'type', 'registration', 'last_update', 'origin', 'destination', 'flight', 'onground', 'vspeed', 'callsign', 'reserved' ],
                            $data
                        );

                    }
                }
            }
            catch (\Exception $e) {
                throw new \Exception(sprintf('An error occurred while fetching and parsing aircrafts for zone %s.', $zoneName));
            }
        }

        return $this->aircrafts;
    }

    /**
     * Fetch and return the flight details for the given flight ID
     *
     * @param   string          $flightId       String Flight Identifier from FlightRadar24
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return  array                           Array of array list of aircrafts as associative array
     *
     * @throws \InvalidArgumentException        If there is no loadbalancer or zone selected
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    public function getAircraftDetailsByFlightId($flightId, $refresh = false)
    {
        $this->getAircrafts($refresh);

        $loadBalancer = $this->getSelectedLoadBalancer();
        if (is_null($loadBalancer)) {
            throw new \InvalidArgumentException('Load balancer not selected.');
        }

        $zoneName = $this->getSelectedZone();
        if (is_null($zoneName)) {
            throw new \InvalidArgumentException('Zone not selected.');
        }

        if (empty($this->aircrafts[$flightId]['details']) || true == $refresh) {
            $url = 'http://' . $loadBalancer['host'] . sprintf(self::PATH_AIRCRAFT_DETAILS, $flightId);

            try {
                $json = file_get_contents($url);
                if ($json) {
                    $this->aircrafts[$zoneName][$flightId]['details'] = json_decode($json, true);
                }
            }
            catch (\Exception $e) {
                throw new \Exception(sprintf('An error occurred while fetching and parsing aircrafts for zone %s.', $zoneName));
            }
        }

        return $this->aircrafts[$zoneName][$flightId];
    }

    /**
     * Find aircraft based on the basic attributes using a regular expression
     *
     * @param   string          $attribute      Attribute to match
     * @param   string          $regexp         Regular expression to use for matching
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return array                            Array of flight IDs that matched
     */
    private function findAircrafts($attribute, $regexp, $refresh = false)
    {
        $this->getAircrafts($refresh);

        $flightIds = [];

        foreach ($this->aircrafts as $id => $data) {
            if (preg_match($regexp, $data[$attribute])) {
                $flightIds[] = $id;
            }
        }

        return $flightIds;
    }

    /**
     * Find and return the basic details of the aircraft based on the basic 
     * attributes using a regular expression
     *
     * @param   string          $attribute      Attribute to match
     * @param   string          $regexp         Regular expression to use for matching
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return array                            Array of aircrafts that matched
     */
    public function getAircraftsByAttribute($attribute, $regexp, $refresh = false)
    {
        $flightIds = $this->findAircrafts($attribute, $regexp, $refresh);

        $aircrafts = [];

        foreach ($flightIds as $flightId) {
            $aircrafts[] = $this->aircrafts[$flightId];
        }

        return $aircrafts;
    }

    /**
     * Find and return the extended details of the aircraft based on the basic 
     * attributes using a regular expression
     *
     * @param   string          $attribute      Attribute to match
     * @param   string          $regexp         Regular expression to use for matching
     * @param   boolean         $refresh        Whether to refetch data from API or not (optional)
     *
     * @return array                            Array of aircrafts that matched
     */
    public function getAircraftDetailsByAttribute($attribute, $regexp, $refresh = false)
    {
        $flightIds = $this->findAircrafts($attribute, $regexp, $refresh);

        $aircraftDetails = [];

        foreach ($flightIds as $flightId) {
            $aircraftDetails[] = $this->getAircraftDetailsByFlightId($flightId, $refresh);
        }

        return $aircraftDetails;
    }

    /**
     * Internal helper method for accessing API and handling JSON data
     *
     * @param   string          $url            String API URL to call
     * 
     * @return  array                           Array from the decoded JSON response
     *
     * @throws \Exception                       If an error occured while fetching or parsing the API
     */
    protected function api($url)
    {
        try {
            // Ignores SSL errors that will appear
            $arrContextOptions=array(
                "ssl"=>array(
                    "verify_peer"=>false,
                    "verify_peer_name"=>false,
                ),
            );  
        
            $json = json_decode(file_get_contents($url,false,stream_context_create($arrContextOptions)), true); 
        }
        catch (\Exception $e) {
            throw new \Exception(sprintf('An error occurred accessing API at %s.', $url));
        }

        return $json;
    }
}
