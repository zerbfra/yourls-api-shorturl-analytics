<?php
/*
Plugin Name: API ShortURL Analytics
Plugin URI: https://github.com/stefanofranco/yourls-api-shorturl-analytics
Description: This plugin define a custom API action 'shorturl_analytics'
Version: 1.0
Author: Stefano Franco
Author URI: https://github.com/stefanofranco/
*/

yourls_add_filter('api_action_shorturl_analytics', 'shorturl_analytics');

/**
 * @return array
 */
function shorturl_analytics(): array
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

    // Need 'shorturl' parameter
    if( !isset( $_REQUEST['shorturl'] ) ) {
        return [
            'statusCode' => 400,
            'message'    => 'Missing shorturl parameter',
        ];
    }
    $shorturl = $_REQUEST['shorturl'];

    // Check if valid shorturl
    if( !yourls_is_shorturl( $shorturl ) ) {
        return [
            'statusCode' => 404,
            'message'    => 'Not found',
        ];
    }

    $stats = extractStats($shorturl, $date_start, $date_end);
    return [
        'statusCode' => 200,
        'message'    => 'success',
        'stats'     => $stats
    ];

}

/**
 * @throws Exception
 */
function extractStats($keyword, $date_start, $date_end = null)
{
    global $ydb;

    if (!empty($date_start)) {

        $date_end = ($date_end ?? $date_start);
        $datesRange = getDateRange($date_start, ($date_end ?? $date_start));

        // Date must be in YYYY-MM-DD format
        $date_start .= ' 00:00:00';
        $date_end .= ' 23:59:59';

        try {
            // Count total numbers of click
            $total_clicks = $ydb->fetchOne("SELECT COUNT(*) as count FROM " . YOURLS_DB_TABLE_LOG . " WHERE `keyword` = :keyword", ['keyword' => $keyword]);
            $daily_clicks = $ydb->fetchPairs("SELECT DATE(`click_time`) as date, COUNT(*) as count FROM " . YOURLS_DB_TABLE_LOG . " WHERE `keyword` = :keyword AND `click_time` BETWEEN :date_start AND :date_end GROUP BY `date`", ['keyword' => $keyword, 'date_start' => $date_start, 'date_end' => $date_end]);
        } catch (\Throwable $e) {
            var_dump($e->getMessage()); die;
        }

        $results = [
            'total_clicks' => (int) $total_clicks[array_key_first($total_clicks)],
            'daily_clicks' => []
        ];

        foreach ($datesRange as $date) {
            $results['daily_clicks'][$date] = (int) ($daily_clicks[$date] ?? 0);
        }

        return $results;
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
 * @throws Exception
 */
function getDateRange($startDate, $endDate): array
{
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end = $end->modify('+1 day');

    $interval = new DateInterval('P1D');
    $dateRange = new DatePeriod($start, $interval ,$end);

    $results = [];
    foreach ($dateRange as $date) {
        $results[] = $date->format('Y-m-d');
    }

    return $results;
}

