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
}

class Curler
{
    private $BaseUrl;

    private $Headers;

    private static $Curl;

    public function __construct($baseUrl, CurlerHeader $headers)
    {
        if (is_null($headers) || ! ($headers instanceof CurlerHeader))
        {
            throw new Exception('Invalid headers.');
        }

        $this->BaseUrl  = $baseUrl;
        $this->Headers  = $headers;

        if ( ! is_resource(self::$Curl))
        {
            self::$Curl = curl_init();
            $this->Reset();
        }
    }

    private function Reset()
    {
        curl_reset(self::$Curl);

        // don't send output to browser
        curl_setopt(self::$Curl, CURLOPT_RETURNTRANSFER, true);

        // fail if HTTP response code is >=400, rather than returning the error page
        curl_setopt(self::$Curl, CURLOPT_FAILONERROR, true);
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

        curl_setopt(self::$Curl, CURLOPT_URL, $this->BaseUrl . $query);

        switch ($requestType)
        {
            case 'GET':

                // nothing to do -- GET is the default
                break;

            case 'POST':

                curl_setopt(self::$Curl, CURLOPT_POST, true);

                break;

            default:

                // allows DELETE, PATCH etc.
                curl_setopt(self::$Curl, CURLOPT_CUSTOMREQUEST, $requestType);
        }
    }

    private function AddData( array $data)
    {
        if ( ! is_null($data))
        {
            $this->Headers->SetHeader('Content-Type', 'application/json');
        }

        curl_setopt(self::$Curl, CURLOPT_POSTFIELDS, is_null($data) ? '' : json_encode($data));
    }

    private function Execute()
    {
        // add headers for authentication etc.
        curl_setopt(self::$Curl, CURLOPT_HTTPHEADER, $this->Headers->GetHeaders());

        // execute the request
        $result = curl_exec(self::$Curl);

        if ($result === false)
        {
            throw new Exception('cURL error: ' . curl_error(self::$Curl));
        }

        return $result;
    }

    private function Close()
    {
        $this->Reset();
    }

    public function Get( array $queryString = null)
    {
        $this->Initialise('GET', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function GetJson( array $queryString = null)
    {
        return json_decode($this->Get($queryString), true);
    }

    public function GetAllHarvest($entityName, array $queryString = null)
    {
        $this->Initialise('GET', $queryString);
        $entities  = array();
        $nextUrl   = null;

        do
        {
            if ($nextUrl)
            {
                curl_setopt(self::$Curl, CURLOPT_URL, $nextUrl);
            }

            $result = json_decode($this->Execute(), true);

            // collect data from response and move on to next page
            $entities  = array_merge($entities, $result[$entityName]);
            $nextUrl   = $result['links']['next'];
        }
        while ($nextUrl);

        $this->Close();

        return $entities;
    }

    public function Post( array $data = null, array $queryString = null)
    {
        $this->Initialise('POST', $queryString);
        $this->AddData($data);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function PostJson( array $data = null, array $queryString = null)
    {
        return json_decode($this->Post($data, $queryString), true);
    }

    public function Delete( array $queryString = null)
    {
        $this->Initialise('DELETE', $queryString);
        $result = $this->Execute();
        $this->Close();

        return $result;
    }

    public function DeleteJson( array $queryString = null)
    {
        return json_decode($this->Delete($queryString), true);
    }
}

class HarvestCredentials
{
    private $AccountId;

    private $Token;

    public $UserId;

    public $FullName;

    public $Email;

    public $IsAdmin;

    public $IsProjectManager;

    public function __construct($accountId, $token)
    {
        $this->AccountId  = $accountId;
        $this->Token      = $token;

        // retrieve our user object (doubles as a credential test)
        $curl  = new Curler(HARVEST_API_ROOT . '/v2/users/me', $this->GetHeaders());
        $me    = $curl->GetJson();

        // if we get to here, the credentials worked
        $this->UserId            = $me['id'];
        $this->FullName          = $me['first_name'] . ' ' . $me['last_name'];
        $this->Email             = $me['email'];
        $this->IsAdmin           = $me['is_admin'];
        $this->IsProjectManager  = $me['is_project_manager'];
    }

    public static function FromName($accountName)
    {
        global $HARVEST_ACCOUNTS;

        if ( ! isset($HARVEST_ACCOUNTS[$accountName]['accountId']) || ! isset($HARVEST_ACCOUNTS[$accountName]['token']))
        {
            throw new Exception("Not enough data for account '$accountName'");
        }

        return new self($HARVEST_ACCOUNTS[$accountName]['accountId'], $HARVEST_ACCOUNTS[$accountName]['token']);
    }

    public function GetHeaders()
    {
        $headers = new CurlerHeader();
        $headers->SetHeader('Harvest-Account-ID', $this->AccountId);
        $headers->SetHeader('Authorization', "Bearer {$this->Token}");

        return $headers;
    }
}

// PRETTY_NESTED_ARRAYS,0

?>