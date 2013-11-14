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
            "select student.stud_code as user_id, eud21_text as login_id, null as password, coalesce(preferred_name, given_name) as first_name, surname as last_name, e_mail as email, 'active' as status
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
            "select telemf.emp_code as user_id, eud21_text as login_id, null as password, coalesce(prefer_name_text, given_names_text) as first_name, surname_text as last_name, e_mail as email, 'active' as status
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

    // don't think "accounts", think "departments"
    "accounts" => array(
        "columns" => array(
            "account_id",    // TASS "dept_code"
            "parent_account_id",    // no nested departments, so leave this blank
            "name",
            "status"    // active
        ),
        "sql" => array(
            "select dept_code as account_id, null as parent_account_id, dept_desc as name, 'active' as status
from subdept
where cmpy_code = '01'
    and not dept_code in ('NO', 'CAS')
order by dept_code"
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

    // think "subjects", not "courses"
    "courses" => array(
        "columns" => array(
            "course_id",    // e.g. 2013T4C7600
            "short_name",    // e.g. 7TEC
            "long_name",    // e.g. Year 7 Technology
            "account_id",    // e.g. TAS
            "term_id",    // e.g. 2013T4
            "status",    // active
            "start_date",    // inherits term dates if not specified
            "end_date"
        ),
        "dateColumns" => array(
            6,
            7
        ),
        "sql" => array(
            "select distinct attendprd.att_year + 'T' + rtrim(attendprd.att_period) + 'C' + tchsub.sub_code as course_id,
    subtab.sub_short as short_name,
    case LEFT(tchsub.sub_code, 1)
        when '7' then 'Year 7'
        when '8' then 'Year 8'
        when '9' then 'Year 9'
        when '0' then 'Year 10'
        when '1' then 'Year 11'
        when '2' then 'Year 12'
        else case LEFT(tchsub.sub_code, 2)
            when '30' then 'Kindy'
            when '31' then 'Year 1'
            when '32' then 'Year 2'
            when '33' then 'Year 3'
            when '34' then 'Year 4'
            when '35' then 'Year 5'
            when '36' then 'Year 6'
        end
    end + ' ' + subtab.sub_long as long_name,
    subtab.dept_code as account_id,
    attendprd.att_year + 'T' + attendprd.att_period as term_id,
    'active' as status,
    null as start_date,
    null as end_date
from attendprd
    inner join tchsub on tchsub.cmpy_code = '01' and attendprd.att_year = tchsub.year_num and attendprd.semester = tchsub.semester
    inner join subtab on subtab.cmpy_code = '01' and tchsub.sub_code = subtab.sub_code
    inner join subdept on subdept.cmpy_code = '01' and subtab.dept_code = subdept.dept_code
where attendprd.cmpy_code = '01'
    and YEAR(attendprd.start_date) <= YEAR(getdate()) + 1
    and attendprd.end_date >= GETDATE()
    and subtab.rpt_flg = 'Y'
    and not subtab.dept_code in ('NO', 'CAS')
    and not subtab.sub_short in ('GC')
order by short_name, course_id"
        ),
    ),

    // think "classes", not "sections"
    "sections" => array(
        "columns" => array(
            "section_id",    // e.g. 2013T4C7600LR2
            "course_id",    // e.g. 2013T4C7600
            "name",    // e.g. Technology LR2
            "status",    // active
            "start_date",    // inherits course dates if not specified
            "end_date"
        ),
        "dateColumns" => array(
            4,
            5
        ),
        "sql" => array(
            "select distinct attendprd.att_year + 'T' + rtrim(attendprd.att_period) + 'C' + tchsub.sub_code + 'L' + tchsub.class as section_id,
    attendprd.att_year + 'T' + rtrim(attendprd.att_period) + 'C' + tchsub.sub_code as course_id,
    rtrim(subtab.sub_long) + ' ' + tchsub.class as name,
    'active' as status,
    null as start_date,
    null as end_date
from attendprd
    inner join tchsub on tchsub.cmpy_code = '01' and attendprd.att_year = tchsub.year_num and attendprd.semester = tchsub.semester
    inner join subtab on subtab.cmpy_code = '01' and tchsub.sub_code = subtab.sub_code
    inner join subdept on subdept.cmpy_code = '01' and subtab.dept_code = subdept.dept_code
where attendprd.cmpy_code = '01'
    and YEAR(attendprd.start_date) <= YEAR(getdate()) + 1
    and attendprd.end_date >= GETDATE()
    and subtab.rpt_flg = 'Y'
    and not subtab.dept_code in ('NO', 'CAS')
    and not subtab.sub_short in ('GC')
order by name, section_id"
        ),
    ),

    // assigns students AND teachers to courses / sections
    "enrollments" => array(
        "columns" => array(
            "course_id",
            "user_id",
            "role",    // "student" or "teacher"
            "section_id",
            "status",    // active, deleted, completed
        ),
        "sql" => array(
            "select distinct null as course_id,
    studsub.stud_code as user_id,
    'student' as role,
    attendprd.att_year + 'T' + rtrim(attendprd.att_period) + 'C' + tchsub.sub_code + 'L' + tchsub.class as section_id,
    'active' as status,
    null as associated_user_id
from attendprd
    inner join tchsub on tchsub.cmpy_code = '01' and attendprd.att_year = tchsub.year_num and attendprd.semester = tchsub.semester
    inner join subtab on subtab.cmpy_code = '01' and tchsub.sub_code = subtab.sub_code
    inner join subdept on subdept.cmpy_code = '01' and subtab.dept_code = subdept.dept_code
    inner join studsub on studsub.cmpy_code = '01' and tchsub.year_num = studsub.yr_study and tchsub.semester = studsub.semester and tchsub.sub_code = studsub.sub_code and tchsub.class = studsub.class
where attendprd.cmpy_code = '01'
    and YEAR(attendprd.start_date) <= YEAR(getdate()) + 1
    and attendprd.end_date >= GETDATE()
    and subtab.rpt_flg = 'Y'
    and not subtab.dept_code in ('NO', 'CAS')
    and not subtab.sub_short in ('GC')
order by section_id, user_id",
            "select null as course_id,
    teacher.emp_code as user_id,
    'teacher' as role,
    attendprd.att_year + 'T' + rtrim(attendprd.att_period) + 'C' + tchsub.sub_code + 'L' + tchsub.class as section_id,
    'active' as status,
    null as associated_user_id
from attendprd
    inner join tchsub on tchsub.cmpy_code = '01' and attendprd.att_year = tchsub.year_num and attendprd.semester = tchsub.semester
    inner join subtab on subtab.cmpy_code = '01' and tchsub.sub_code = subtab.sub_code
    inner join subdept on subdept.cmpy_code = '01' and subtab.dept_code = subdept.dept_code
    inner join teacher on teacher.cmpy_code = '01' and tchsub.tch_code = teacher.tch_code
where attendprd.cmpy_code = '01'
    and YEAR(attendprd.start_date) <= YEAR(getdate()) + 1
    and attendprd.end_date >= GETDATE()
    and subtab.rpt_flg = 'Y'
    and not subtab.dept_code in ('NO', 'CAS')
    and not subtab.sub_short in ('GC')
order by section_id, user_id"
        ),
    ),
    "groups" => array(
        "columns" => array(
            "group_id",
            "account_id",
            "name",
            "status",    // available, closed, completed, deleted
        ),
        "sql" => array(),
    ),
    "groups_membership" => array(
        "columns" => array(
            "group_id",
            "user_id",
            "status",    // accepted, deleted
        ),
        "sql" => array(),
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