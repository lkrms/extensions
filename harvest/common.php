<?php

define('HARVEST_ROOT', dirname(__FILE__));
define('HARVEST_API_ROOT', 'https://api.harvestapp.com');

// load configuration options
require_once (HARVEST_ROOT . '/config.php');

class CurlerHeader
{
    private $Headers = array('User-Agent' => 'User-Agent:Curler PHP library (https://github.com/lkrms/extensions)');

    public function SetHeader($name, $value)
    {
        $name   = trim($name);
        $value  = trim($value);

        // HTTP headers are case-insensitive, so make sure we don't end up with duplicates
        $this->Headers [strtolower($name)] = "{$name}:{$value}";
    }

    public function GetHeaders()
    {
        return array_values($this->Headers);
    }

    public static function GetHarvestApiHeaders($harvestAccountId, $harvestToken)
    {
        $headers = new self;
        $headers->SetHeader('Harvest-Account-ID', $harvestAccountId);
        $headers->SetHeader('Authorization', "Bearer $harvestToken");

        return $headers;
    }
}

class Curler
{
    private $BaseUrl;

    private $Headers;

    private $Curl;

    public function __construct($baseUrl, CurlerHeader $headers)
    {
        if (is_null($headers) || ! ($headers instanceof CurlerHeader))
        {
            throw new Exception('Invalid headers.');
        }

        $this->BaseUrl  = $baseUrl;
        $this->Headers  = $headers;
    }

    private function Initialise($requestType = 'GET', array $queryString = null)
    {
        $query = '';

        if (is_array($queryString))
        {
            foreach ($queryString as $name => $value)
            {
                $query .= ($query ? '&' : '?') . "{$name}=" . urlencode($value);
            }
        }

        $this->Curl = curl_init($this->BaseUrl . $query);

        // allows GET, POST, DELETE, etc.
        curl_setopt($this->Curl, CURLOPT_CUSTOMREQUEST, $requestType);

        // don't send output to browser
        curl_setopt($this->Curl, CURLOPT_RETURNTRANSFER, true);

        // fail if HTTP response code is >=400, rather than returning the error page
        curl_setopt($this->Curl, CURLOPT_FAILONERROR, true);
    }

    private function AddData( array $data)
    {
        $this->Headers->SetHeader('Content-Type', 'application/json');
        curl_setopt($this->Curl, CURLOPT_POSTFIELDS, json_encode($data));
    }

    private function Execute()
    {
        // add headers for authentication etc.
        curl_setopt($this->Curl, CURLOPT_HTTPHEADER, $this->Headers->GetHeaders());

        // execute the request
        $result = curl_exec($this->Curl);

        if ($result === false)
        {
            throw new Exception('cURL error: ' . curl_error($this->Curl));
        }

        return $result;
    }

    private function Close()
    {
        curl_close($this->Curl);
    }

    public function Get( array $queryString = null)
    {
        $this->Initialise('GET', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function GetAllHarvest($entityName, array $queryString = null)
    {
        $entities  = array();
        $nextUrl   = null;

        do
        {
            $this->Initialise('GET', $queryString);

            if ($nextUrl)
            {
                curl_setopt($this->Curl, CURLOPT_URL, $nextUrl);
            }

            $result = json_decode($this->Execute(), true);
            $this->Close();

            // collect data from response and move on to next page
            $entities  = array_merge($entities, $result[$entityName]);
            $nextUrl   = $result['links']['next'];
        }
        while ($nextUrl);

        return $entities;
    }

    public function Post( array $data = null, array $queryString = null)
    {
        $this->Initialise('POST', $queryString);

        if ( ! is_null($data))
        {
            $this->AddData($data);
        }

        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function Delete( array $queryString = null)
    {
        $this->Initialise('DELETE', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }
}

// PRETTY_NESTED_ARRAYS,0

?>