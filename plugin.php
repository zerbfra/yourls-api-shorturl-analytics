<?php
/*
Plugin Name: API ShortURL Search Analytics
Plugin URI: https://github.com/zerbfra/yourls-api-shorturl-analytics
Description: This plugin defines a custom API action 'shorturl_search_analytics'
Version: 1.0.0
Author: Stefano Franco, forked by Zerbinati Francesco
Author URI: https://github.com/stefanofranco/
*/

yourls_add_filter('api_action_shorturl_search_analytics', 'shorturl_search_analytics');
yourls_add_filter('api_action_shorturl_clicks_interval', 'shorturl_clicks_interval');
yourls_add_filter('api_action_clicks_interval_after_third_by_date', 'clicks_after_third_per_shorturl_by_date');

/**
 * @return array
 * @throws Exception
 */
function shorturl_search_analytics(): array
{

    // The date parameter must exist
    if( !isset( $_REQUEST['date'] ) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing date parameter',
        ];
    }
    $date_start = $_REQUEST['date'];
    $date_end = $_REQUEST['date_end'] ?? $date_start;

    // Check if the date format is right
    if (
        !checkDateFormat($date_start) ||
        !checkDateFormat($date_end)
    ) {
        return [
            'statusCode' => 400,
            'message'    => 'Wrong date format',
        ];
    }

    // Check if "date_end" is not smaller than "date_start"
    if( $date_end < $date_start ) {
        return [
            'statusCode' => 400,
            'message'    => 'The date_end parameter cannot be smaller than date',
        ];
    }

    // Need 'search' parameter
    if( !isset( $_REQUEST['search'] ) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing search parameter',
        ];
    }
    $search = $_REQUEST['search'];

    $field = 'url';
    if(isset( $_REQUEST['field']) ) {
        $field = $_REQUEST['field'];
    }

    $stats = extractStats($search, $field, $date_start, $date_end);
    return [
        'statusCode' => 200,
        'message'    => 'success',
        'stats'     => $stats
    ];

}

/**
 * @throws Exception
 */
function extractStats($search, $field = 'url', $date_start, $date_end = null)
{
    global $ydb;

    if (!empty($date_start)) {

        $date_end = ($date_end ?? $date_start);

        // Date must be in YYYY-MM-DD format
        $date_start .= ' 00:00:00';
        $date_end .= ' 23:59:59';

        try {
            // Get stats for all links with search term
            $searchTerm = '%'.$search.'%';
            $search_clicks = $ydb->fetchAll("SELECT keyword, url, title, clicks FROM " . YOURLS_DB_TABLE_URL . " WHERE `".$field."` LIKE (:search) AND `timestamp` BETWEEN :date_start AND :date_end ORDER BY clicks DESC", ['search' => $searchTerm, 'date_start' => $date_start, 'date_end' => $date_end]);
        } catch (\Throwable $e) {
            var_dump($e->getMessage()); die;
        }

        return $search_clicks;
    }
    return [];
}

/**
 * Check if a date is in the format 'Y-m-d'.
 *
 * @param string $date The date to check for.
 * @param string $format
 * @return bool True if $date format is equal to the one specified by $format
 * (default: 'Y-m-d'). Otherwise, the function returns False.
 */

function checkDateFormat($date, $format='Y-m-d'): bool
{
    $dateObject = DateTime::createFromFormat($format, $date);
    return $dateObject && $dateObject->format($format) === $date;
}

/**
 * Check if a date or datetime is in accepted format 'Y-m-d' or 'Y-m-d H:i:s'.
 *
 * @param string $dateTime
 * @return bool
 */
function checkDateOrDateTimeFormat( $dateTime ) {
    // Try full datetime first
    $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $dateTime );
    if ( $dt && $dt->format( 'Y-m-d H:i:s' ) === $dateTime ) {
        return true;
    }
    // Try just date
    $d = DateTime::createFromFormat( 'Y-m-d', $dateTime );
    return (bool) ( $d && $d->format( 'Y-m-d' ) === $dateTime );
}

/**
 * API action: return click count for one or more shorturl(s) in a date interval.
 *
 * @return array
 */
function shorturl_clicks_interval() {
    // shorturl(s) parameter required
    if ( !isset($_REQUEST['shorturl']) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing shorturl parameter',
        ];
    }
    // Split on comma, trim and remove empty values
    $keywordsRaw = $_REQUEST['shorturl'];
    $keywords    = array_filter(array_map('trim', explode(',', $keywordsRaw)));
    if ( empty($keywords) ) {
        return [
            'statusCode' => 400,
            'message'    => 'No valid shorturl(s) provided',
        ];
    }

    // date_start parameter required
    if ( !isset($_REQUEST['date_start']) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing date_start parameter',
        ];
    }
    $date_start = $_REQUEST['date_start'];
    $date_end   = isset($_REQUEST['date_end'])
                ? $_REQUEST['date_end']
                : date('Y-m-d H:i:s');

    // Validate date or datetime formats
    if ( !checkDateOrDateTimeFormat($date_start) || !checkDateOrDateTimeFormat($date_end) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Wrong date or datetime format; use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS',
        ];
    }

    // Ensure date_end ≥ date_start
    if ( $date_end < $date_start ) {
        return [
            'statusCode' => 400,
            'message'    => 'The date_end parameter cannot be earlier than date_start',
        ];
    }

    // Build full timestamps for the interval
    $start_ts = (strpos($date_start, ' ') === false)
              ? $date_start . ' 00:00:00'
              : $date_start;
    $end_ts   = (strpos($date_end,   ' ') === false)
              ? $date_end   . ' 23:59:59'
              : $date_end;

    global $ydb;
    try {
        // Prepara placeholder dinamici per IN (...)
        $inPlaceholders = [];
        $params = [
            'start' => $start_ts,
            'end'   => $end_ts,
        ];
        foreach ($keywords as $idx => $kw) {
            $ph = "k{$idx}";
            $inPlaceholders[] = ":{$ph}";
            $params[$ph] = $kw;
        }
        $inList = implode(', ', $inPlaceholders);

        // Query con grouping per keyword
        $sql = "
            SELECT shorturl, COUNT(*) AS clicks
            FROM " . YOURLS_DB_TABLE_LOG . "
            WHERE shorturl IN ($inList)
              AND click_time BETWEEN :start AND :end
            GROUP BY shorturl
        ";
        $rows = $ydb->fetchAll($sql, $params);

        // Costruisci array di default con 0 per chi non compare
        $results = array_fill_keys($keywords, 0);
        foreach ($rows as $row) {
            $results[$row['shorturl']] = (int) $row['clicks'];
        }

        // Prepara output
        $data = [];
        foreach ($results as $shorturl => $count) {
            $data[] = [
                'shorturl' => $shorturl,
                'clicks'   => $count,
            ];
        }

    } catch ( \Throwable $e ) {
        return [
            'statusCode' => 500,
            'message'    => 'Database error: ' . $e->getMessage(),
        ];
    }

    return [
        'statusCode' => 200,
        'message'    => 'success',
        'data'       => [
            'date_start' => $date_start,
            'date_end'   => $date_end,
            'results'    => $data,
        ],
    ];
}

/**
 * API action: per ogni shorturl, return click count in the 10 minutes
 * following its third click on a given date.
 *
 * @return array
 */
function clicks_after_third_per_shorturl_by_date() {
    // date parameter required, formato YYYY-MM-DD
    if (!isset($_REQUEST['date'])) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing date parameter',
        ];
    }
    $date = trim($_REQUEST['date']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [
            'statusCode' => 400,
            'message'    => 'Wrong date format; use YYYY-MM-DD',
        ];
    }
    list($y, $m, $d) = explode('-', $date);
    if (!checkdate((int)$m, (int)$d, (int)$y)) {
        return [
            'statusCode' => 400,
            'message'    => 'Invalid date',
        ];
    }

    $start_of_day = $date . ' 00:00:00';
    $end_of_day   = $date . ' 23:59:59';

    global $ydb;
    try {
        // 1) Recupera tutti i shorturl che hanno click in quella data
        $sqlUrls = "
            SELECT DISTINCT shorturl
            FROM " . YOURLS_DB_TABLE_LOG . "
            WHERE click_time BETWEEN :start AND :end
        ";
        $rowsUrls = $ydb->fetchAll($sqlUrls, [
            'start' => $start_of_day,
            'end'   => $end_of_day,
        ]);

        $results = [];

        // 2) Per ciascun shorturl calcola il terzo click e i click nella finestra
        foreach ($rowsUrls as $r) {
            $kw = $r['shorturl'];

            // 2a) timestamp del 3° click di questo shorturl
            $sql3 = "
                SELECT click_time
                FROM " . YOURLS_DB_TABLE_LOG . "
                WHERE shorturl = :kw
                  AND click_time BETWEEN :start AND :end
                ORDER BY click_time ASC
                LIMIT 1 OFFSET 2
            ";
            $third = $ydb->fetchOne($sql3, [
                'kw'    => $kw,
                'start' => $start_of_day,
                'end'   => $end_of_day,
            ]);

            if (!$third) {
                // meno di 3 click → zero
                $results[] = [
                    'shorturl'           => $kw,
                    'third_click_time'   => null,
                    'window_end_time'    => null,
                    'clicks_in_window'   => 0,
                ];
                continue;
            }

            $third_ts = $third['click_time'];
            $end_ts   = date('Y-m-d H:i:s', strtotime($third_ts . ' +10 minutes'));

            // 2b) conta click nella finestra per questo shorturl
            $sqlCount = "
                SELECT COUNT(*) AS cnt
                FROM " . YOURLS_DB_TABLE_LOG . "
                WHERE shorturl = :kw
                  AND click_time BETWEEN :third AND :endwin
            ";
            $countRow = $ydb->fetchOne($sqlCount, [
                'kw'     => $kw,
                'third'  => $third_ts,
                'endwin' => $end_ts,
            ]);
            $cnt = (int)$countRow['cnt'];

            $results[] = [
                'shorturl'           => $kw,
                'third_click_time'   => $third_ts,
                'window_end_time'    => $end_ts,
                'clicks_in_window'   => $cnt,
            ];
        }

    } catch (\Throwable $e) {
        return [
            'statusCode' => 500,
            'message'    => 'Database error: ' . $e->getMessage(),
        ];
    }

    // Ordina per clicks_in_window desc
    usort($results, function($a, $b) {
        return $b['clicks_in_window'] <=> $a['clicks_in_window'];
    });


    return [
        'statusCode' => 200,
        'message'    => 'success',
        'data'       => [
            'date'    => $date,
            'results' => $results,
        ],
    ];
}