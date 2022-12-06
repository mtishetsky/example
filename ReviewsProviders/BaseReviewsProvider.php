<?php

namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

abstract class BaseReviewsProvider
{
    const PRODUCTS_PER_REQUEST = 50;
    const DEBUG_ALL_TYPES = 'ALL';
    const DEBUG_TYPE_REQUEST  = 'request';
    const DEBUG_TYPE_RESPONSE = 'response';
    const DEBUG_TYPE_HEADERS  = 'headers';
    const DEBUG_TYPE_PRODUCTS = 'products';
    const DEBUG_TYPE_PRODUCT  = 'product';

    const ERROR_EMPTY_RESPONSE_HEADER = 'Empty RESPONSE header';
    const ERROR_RESPONSE_HEADER_NOT_OK = 'RESPONSE header is not 200 OK';
    const ERROR_UNABLE_DECODE_SUMMARY_RESPONSE = 'Unable to decode totals json';
    const ERROR_EMPTY_SUMMARY_RESPONSE = 'Decoded totals response is empty';
    const ERROR_UNABLE_DECODE_REVIEWS_RESPONSE = 'Unable to decode reviews json';
    const ERROR_EMPTY_REVIEWS_RESPONSE = 'Decoded reviews response is empty';

    const MAX_RETRIES = 5;
    const RETRY_DELAY_SECONDS = 3;
    const TABLE_NAME = 'cscart_received_reviews';
    const TABLE_DEF = "
    CREATE TABLE `%s` (
        product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        total_reviews INT UNSIGNED NOT NULL DEFAULT 0,
        reviews_average_score FLOAT UNSIGNED NOT NULL DEFAULT 0,
        reviews_average_score_titles ENUM('nostar', 'onestar', 'twostar', 'threestar', 'fourstar', 'fivestar') DEFAULT 'nostar',
        reviews_messages MEDIUMTEXT NOT NULL DEFAULT '',
        last_update TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )";

    protected static $skip_hash_check = false;
    protected static $db_connection_prefix = 'user';
    protected static $allow_update_summary = true;

    protected $engine_data;
    protected $required_engine_data = [];
    protected $logger;
    protected $options = [];
    protected $totals_count = 0;
    protected $testing_counter = 0;
    protected $last_message = '';

    protected $last_error = null;
    protected $fatal_errors = [];
    protected $retries = [];

    public function __construct($engine_data = [])
    {
        if (empty($engine_data['bypass_required_data'])) {
            $this->checkRequiredData($engine_data);
        }
        $this->engine_data = $engine_data;

        $this->fatal_errors = array_merge($this->fatal_errors, [
            static::ERROR_EMPTY_RESPONSE_HEADER => 1,
            static::ERROR_RESPONSE_HEADER_NOT_OK => 1,
            static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE => 1,
            static::ERROR_EMPTY_SUMMARY_RESPONSE => 1,
            static::ERROR_UNABLE_DECODE_REVIEWS_RESPONSE => 1,
            static::ERROR_EMPTY_REVIEWS_RESPONSE => 1,
        ]);
    }

    public static function createTable()
    {
        require_once DIR_CORE . '/fn.upgrades.php';

        fn_upgrades_query_prefix(static::$db_connection_prefix);

        if (!empty(static::TABLE_DEF) && !fn_upgrades_check_table_exists(static::TABLE_NAME)) {
            return db_query(static::getDbPrefix() . sprintf(static::TABLE_DEF, static::TABLE_NAME));
        }

        return true;
    }

    protected function checkRequiredData($engine_data)
    {
        foreach ($this->required_engine_data as $k) {
            if (empty($engine_data[$k])) {
                throw $this->makeException('Required engine_data field is empty: ' . $k);
            }
        }
    }

    public function getAllProductReviewsTotals()
    {
        fn_cs_switch_db($this->engine_data);

        $limit = 10000;
        $last_id = 0;

        while (true) {
            $big_product_ids = db_get_fields("user#SELECT product_id FROM ?:summary WHERE product_id > $last_id ORDER BY product_id LIMIT $limit");

            if (empty($big_product_ids)) {
                break;
            }

            $last_id = end($big_product_ids);
            $product_ids_chunks = array_chunk($big_product_ids, static::PRODUCTS_PER_REQUEST);

            foreach ($product_ids_chunks as $product_ids) {
                $totals = $this->getProductsSummary($product_ids);

                $this->totals_count += $this->saveTotals($totals);

                if ($this->isTesting() || $this->isLastErrorFatal()) {
                    return $this->totals_count;
                }
            }
        }

        return $this->totals_count;
    }

    /**
     * @param $product_id int
     * @return array
     */
    public function getProductReviewsTotals($product_id)
    {
        $summary = $this->getProductsSummary([$product_id]);
        if (!is_null($summary)) { // method is implemented
            return empty($summary[$product_id]) ? $this->getEmptyTotals() : $summary[$product_id];
        }

        if (empty($this->totals_count)) {
            $this->totals_count = $this->getAllProductReviewsTotals();
        }

        $res = db_get_array(static::getDbPrefix() .
            'SELECT ?p FROM ?f WHERE product_id = ?i',
            join(',', array_keys($this->getEmptyTotals())),
            static::TABLE_NAME,
            $product_id
        );

        return empty($res) ? $this->getEmptyTotals() : $res;
    }

    /**
     * @param $product_ids array
     * @return array|null
     */
    protected function getProductsSummary($product_ids)
    {
        return null;
    }

    public function getRequiredEngineData()
    {
        return $this->required_engine_data;
    }

    /**
     * @return int number of reviews
     */
    public function getAllProductReviews()
    {
        return 0;
    }

    public function testConnect()
    {
        return true;
    }

    public function getEmptyTotals()
    {
        return [
            'total_reviews' => 0,
            'reviews_average_score' => 0,
            'reviews_average_score_titles' => 0,
        ];
    }

    public function getAverageScoreTitle($average_score)
    {
        if (0 < $average_score && $average_score <= 1.75) {
            return 'onestar';
        }

        if (1.75 < $average_score && $average_score <= 2.75) {
            return 'twostar';
        }

        if (2.75 < $average_score && $average_score <= 3.75) {
            return 'threestar';
        }

        if (3.75 < $average_score && $average_score <= 4.75) {
            return 'fourstar';
        }

        if (4.75 < $average_score && $average_score <= 5.1) {
            return 'fivestar';
        }

        return 'nostar';
    }

    public function getEngineData($key = null)
    {
        if (is_null($key)) {
            return $this->engine_data;
        }

        if (!isset($this->engine_data[$key])) {
            return null;
        }

        return $this->engine_data[$key];
    }

    protected function formatMessage($args)
    {
        static $lastMessage;

        foreach ($args as $k => $a) {
            if (empty($a)) {
                unset($args[$k]);
                continue;
            }

            if (!is_scalar($a)) {
                $args[$k] = (is_array($a) && (count($a) == 1) && is_scalar(current($a))) ? current($a) : print_r($a, true);
            }
        }

        $message = join("\n", $args);

        if ($message == $lastMessage) {
            return $message;
        }

        $lastMessage = sprintf('In %s for engine %s: %s', static::class, $this->getEngineData('engine_id'), $message);

        return $lastMessage;
    }

    public function log()
    {
        $callable = $this->getLogger();
        call_user_func($callable, $this->formatMessage(func_get_args()));

        return true;
    }

    protected function makeException()
    {
        return new \Exception($this->formatMessage(func_get_args()));
    }

    public function setTesting($value)
    {
        $this->testing_counter = (int)$value;
        $this->options['testing'] = $value;
    }

    public function isTesting()
    {
        empty($this->testing_counter) ?: $this->testing_counter -= 1;
        return !empty($this->options['testing']) && empty($this->testing_counter);
    }

    public function setDebug($types)
    {
        if ($types === static::DEBUG_ALL_TYPES) {
            return $this->options['debug'] = $types;
        }

        if (is_string($types)) {
            $types = explode(',', $types);
        }

        // request response headers products_summary product_summary

        foreach ($types as $type) {
            $type = mb_strtolower(trim($type));
            $this->options['debug'][mb_strtolower($type)] = 1;
        }

        return true;
    }

    public function debug($type, $message)
    {
        if (empty($this->options['debug'])) {
            return null;
        }

        $type = mb_strtolower(trim($type));

        if ($this->options['debug'] !== static::DEBUG_ALL_TYPES && empty($this->options['debug'][$type])) {
            return null;
        }

        $message = is_array($message) ? print_r($message, true) :  $message;

        return $this->log($type . ': ' . $message);
    }

    public function setLogger($callable)
    {
        $this->logger = $callable;
    }

    /**
     * @return callable
     */
    protected function getLogger()
    {
        if (isset($this->logger) && is_callable($this->logger)) {
            return $this->logger;
        }

        $functions = [
            'fn_print_log',
            'error_log',
        ];

        foreach ($functions as $f) {
            if (function_exists($f)) {
                return $f;
            }
        }

        return 'print';
    }

    public static function isHttpHeader200($header)
    {
        return preg_match('/ 200( OK)?/i', $header);
    }

    public function isLastErrorFatal()
    {
        if (empty($this->last_error)) {
            return false;
        }

        if (!empty($this->fatal_errors[$this->last_error])) { // exact match
            return true;
        }

        foreach ($this->fatal_errors as $e => $num) {
            if (strpos($this->last_error, $e) !== false) { // e is part of last error
                return true;
            }
        }

        return false;
    }

    public static function skipHashCheck()
    {
        return static::$skip_hash_check;
    }

    public function proceedRetry($error, $params = [])
    {
        $params = array_merge([
            'log' => true,
            'sleep' => true,
            'args' => [],
        ], $params);

        if (!isset($this->retries[$error])) {
            $this->retries[$error] = 0;
        }

        $is_fatal = ($error === $this->last_error) && $this->isLastErrorFatal();
        $can_retry = !$is_fatal && $this->retries[$error] < static::MAX_RETRIES;
        $this->retries[$error] += 1;

        if (!empty($params['log'])) {
            if ($is_fatal) {
                $message = $error;
            } else {
                $message = $can_retry
                    ? sprintf('%s, retry #%s', $error, $this->retries[$error])
                    : sprintf('%s, too many retries, break', $error);
            }

            array_unshift($params['args'], $message);
            call_user_func_array([$this, 'log'], $params['args']);
        }

        if ($can_retry && (int)$params['sleep'] > 0) {
            $delay = is_int($params['sleep']) ? $params['sleep'] : static::RETRY_DELAY_SECONDS;
            sleep($delay);
        }

        return $can_retry;
    }

    public function resetRetry($error = null)
    {
        if (is_null($error)) {
            return $this->retries = [];
        }

        return $this->retries[$error] = 0;
    }

    /**
     * @param $request \Core\GraphQL\ShopifyGraphQL
     * @return string
     */
    public function getGraphqlRequestDebug($request)
    {
        return sprintf(
            "curl -Lgs '%s' -X POST -d '%s' -H 'Content-type: application/graphql' -H '%s'",
            $request->getLastRequestUrl(),
            $request->getQuery($request->getLastRequestRootNode()),
            join("' -H '", $request->getLastRequestHeaders())
        );
    }

    public function clearExistingData()
    {
        return db_query(static::getDbPrefix() . 'TRUNCATE TABLE ?f', static::TABLE_NAME);
    }

    public function saveReviews(& $reviews)
    {
        foreach ($reviews as $id => $data) {
            $data = array_map(function($d){
                return join(' ', $d);
            }, $data);

            db_query(static::getDbPrefix() .
                'UPDATE ?f SET reviews_messages = ?s WHERE product_id = ?i',
                static::TABLE_NAME,
                join(' ', $data),
                $id
            );
        }

        return count($reviews);
    }

    /**
     * @param $totals array
     * @return int number of totals passed
     */
    public function saveTotals(& $totals)
    {
        if (empty($this->totals_count)) {
            $this->clearExistingData();
        }

        $fields = [
            'total_reviews',
            'reviews_average_score',
            'reviews_average_score_titles',
            'reviews_messages',
        ];

        $values = [];
        foreach ($totals as $id => $data) {
            $to_insert = ['product_id' => $id];
            foreach ($fields as $f) {
                $to_insert[$f] = isset($data[$f]) ? $data[$f] : ''; // preserve fields order
            }

            $to_insert['last_update'] = date('Y-m-d H:i:s');
            $values[] = $to_insert;

            if (count($values) == 100) {
                static::executeInsertQuery($fields, $values);
                $values = [];
            }
        }

        if (!empty($values)) {
            static::executeInsertQuery($fields, $values);
        }

        return count($totals);
    }

    public static function executeInsertQuery(array $fields, array $values)
    {
        $on_update = join(', ', array_map(fn($f) => "$f = VALUES($f)", $fields));
        $fields = join(', ', array_map('db_field', [...['product_id'],...array_values($fields), ...['last_update']]));

        return db_query(static::getDbPrefix() .
            'INSERT INTO ?f (?p) VALUES ?em ON DUPLICATE KEY UPDATE ?p',
            static::TABLE_NAME,
            $fields,
            $values,
            $on_update
        );
    }

    protected static function getDbPrefix()
    {
        return empty(static::$db_connection_prefix) ? '' : rtrim(static::$db_connection_prefix, '#') . '#';
    }

    public static function setDbPrefix($value)
    {
        static::$db_connection_prefix = $value;
    }

    public static function getTotalsHash()
    {
        db_query(static::getDbPrefix() . 'SET session group_concat_max_len=1048575');
        return db_get_field(static::getDbPrefix() . "
SELECT
    sha1(GROUP_CONCAT(val SEPARATOR ''))
FROM (
    SELECT

        substr(sha1(CONCAT(product_id, total_reviews, reviews_average_score)), 1, 5) AS val
    FROM
        " . static::TABLE_NAME . "
    ORDER BY
        product_id
) AS X
", static::TABLE_NAME);
    }

    public static function getTableDefinition()
    {
        return sprintf(static::TABLE_DEF, static::TABLE_NAME);
    }

    public function getLastMessage()
    {
        return $this->last_message;
    }

    public function updateSummaryReviewsTotals($force = false)
    {
        if (!isset(static::$allow_update_summary) || static::$allow_update_summary !== true) {
            $this->last_message = 'Updating summary is disabled';

            return false;
        }

        if (empty($force) && $this->engine_data['reviews_hash'] == static::getTotalsHash()) {
            $this->last_message = 'Reviews hash matches, skip updating summary';

            return true;
        }

        if ($this->isLastErrorFatal()) {
            $this->last_message = 'Last error is fatal, skip updating summary: ' . $this->last_error;

            return false;
        }

        $res = true;

        $qs[] = "
UPDATE
    ?:summary AS s
JOIN ?f AS r
ON
    s.product_id = r.product_id
SET
    s.total_reviews = r.total_reviews,
    s.reviews_average_score = r.reviews_average_score,
    s.reviews_average_score_titles = r.reviews_average_score_titles,
    s.reviews_messages = r.reviews_messages
";

        $qs[] = "
UPDATE
    ?:summary AS s
LEFT JOIN ?f AS r
ON
    s.product_id = r.product_id
SET
    s.total_reviews = 0,
    s.reviews_average_score = 0,
    s.reviews_average_score_titles = 'nostar',
    s.reviews_messages = ''
WHERE
    r.product_id IS NULL
";

        foreach ($qs as $q) {
            $res = $res && db_query(static::getDbPrefix() . $q, static::TABLE_NAME);
        }

        $res &= fn_update_engine_extra([
            'reviews_hash' => static::getTotalsHash()
        ], $this->engine_data['engine_id']);

        $this->last_message = $res ? 'Summary reviews updated' : 'Failed to update summary reviews!';

        return $res;
    }
}
