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



    $stats = extractStats($search, $date_start, $date_end);
    return [
        'statusCode' => 200,
        'message'    => 'success',
        'stats'     => $stats
    ];

}

/**
 * @throws Exception
 */
function extractStats($search, $date_start, $date_end = null)
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
            $search_clicks = $ydb->fetchAll("SELECT keyword, url, title, clicks FROM " . YOURLS_DB_TABLE_URL . " WHERE `url` LIKE (:search) AND `timestamp` BETWEEN :date_start AND :date_end ORDER BY clicks DESC", ['search' => $searchTerm, 'date_start' => $date_start, 'date_end' => $date_end]);
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
