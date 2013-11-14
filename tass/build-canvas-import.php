<?php

define("TASS_ROOT", dirname(__file__));
require_once (TASS_ROOT . "/common.php");

// MCS doesn't provide individual user accounts for K-2
$minYear = 3;

// Canvas accepts SIS data as a set of CSV files. This is where we define each CSV's name, columns and queries (imagine a UNION operator between each query).
$csv = array(
    "users" => array(
        "columns" => array(
            "user_id",    // TASS student / employee code
            "login_id",    // AD username
            "password",    // empty!
            "first_name",
            "last_name",
            "email",
            "status",    // "active" or "deleted"
        ),
        "sql" => array(

            // all current students
            "select student.stud_code as user_id, eud21_text as login_id, '' as password, coalesce(preferred_name, given_name) as first_name, surname as last_name, e_mail as email, 'active' as status
from student
    inner join studeuddata on student.stud_code = studeuddata.stud_code and studeuddata.cmpy_code = '01' and studeuddata.area_code = 4
where student.cmpy_code = '01'
    and year_grp >= $minYear
    and doe <= GETDATE()
    and (dol is null or dol >= GETDATE())
    and eud21_text is not null
    and e_mail is not null
order by last_name, first_name",

            // all current permanent staff (including admin staff)
            "select telemf.emp_code as user_id, eud21_text as login_id, '' as password, coalesce(prefer_name_text, given_names_text) as first_name, surname_text as last_name, e_mail as email, 'active' as status
from telemf
    inner join tel_euddata on telemf.emp_code = tel_euddata.emp_code and tel_euddata.cust_code = '01' and tel_euddata.area_code = 4
where telemf.cust_code = '01'
    and start_date <= GETDATE()
    and (term_date is null or term_date >= GETDATE())
    and status_text in ('F', 'P')
    and eud21_text is not null
    and e_mail is not null
order by last_name, first_name",
        ),
    ),
    "terms" => array(
        "columns" => array(
            "term_id",    // e.g. 2013T4
            "name",    // e.g. Term 4 2013
            "status",    // active
            "start_date",
            "end_date"
        ),
        "dateColumns" => array(
            3,
            4
        ),
        "sql" => array(
            "select att_year + 'T' + att_period as term_id, att_desc as name, 'active' as status, start_date, end_date
from attendprd
where cmpy_code = '01'
    and YEAR(start_date) <= YEAR(getdate()) + 1
    and end_date >= GETDATE()
order by term_id"
        ),
    ),
);

// make sure we can connect to TASS
$db = mssql_connect(TASS_DB_SERVER, TASS_DB_USERNAME, TASS_DB_PASSWORD);

if ( ! $db || ! mssql_select_db(TASS_DB_NAME))
{
    exit ("Unable to connect to TASS database.");
}

foreach ($csv as $csvName => $csvMeta)
{
    $lines = array();
    $lines[] = implode(",", $csvMeta["columns"]);

    foreach ($csvMeta["sql"] as $sql)
    {
        $rs = mssql_query($sql, $db);

        if ( ! $rs)
        {
            exit ("MSSQL error: " . mssql_get_last_message());
        }

        while ($row = mssql_fetch_row($rs))
        {
            foreach ($row as $i => & $cell)
            {
                if (is_null($cell))
                {
                    continue;
                }
                elseif (isset($csvMeta["dateColumns"]) && in_array($i, $csvMeta["dateColumns"]))
                {
                    $cell = date("Y-m-d\TH:i:sP", strtotime($cell));
                }
                else
                {
                    $count = 0;
                    $cell = trim(str_replace('"', '""', $cell, $count));

                    if ($count || strpos($cell, ",") !== false)
                    {
                        $cell = '"' . $cell . '"';
                    }
                }
            }

            $lines[] = implode(",", $row);
        }
    }

    file_put_contents(TASS_ROOT . "/.tmp/$csvName.csv", implode("\r\n", $lines) . "\r\n");
}

mssql_close($db);

// PRETTY_ALIGN,0

?>