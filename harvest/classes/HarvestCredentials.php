<?php

class HarvestCredentials
{
    private $AccountId;

    private $Token;

    public $UserId;

    public $FullName;

    public $Email;

    public $IsAdmin;

    public $IsProjectManager;

    public $CompanyName;

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

        // get company details too
        $curl               = new Curler(HARVEST_API_ROOT . '/v2/company', $this->GetHeaders());
        $company            = $curl->GetJson();
        $this->CompanyName  = $company['name'];
    }

    public function GetAccountId()
    {
        return $this->AccountId;
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
