<?php

namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Rivyo extends BaseReviewsProvider
{
    const TOTALS_FEED_URL      = "https://thimatic-apps.com/product_review/api/app_store_data.php";
    const PRODUCTS_PER_REQUEST = 100;
    const MAX_FEED_PARAM_LEN   = 2048;

    protected $engine_host;
    protected $required_engine_data = [
        'shopify_access_token',
    ];
    protected $fatal_errors = [
        'Invalid Store Name or access denied.' => 1,
    ];

    public function __construct($engine_data)
    {
        parent::__construct($engine_data);

        $parsed_engine_name = parse_url($engine_data['name']);

        if (empty($parsed_engine_name['host'])) {
            throw $this->makeException('Engine hostname is incorrect');
        }

        $this->engine_host = $parsed_engine_name['host'];
    }

    public function getAllProductReviewsTotals()
    {
        fn_cs_switch_db($this->engine_data);

        $limit = 1000;
        $last_id = 0;

        while (true) {
            $products_data = db_get_array("user#SELECT product_id, SNIZE_data FROM ?:summary WHERE product_id > $last_id ORDER BY product_id LIMIT $limit");

            if (empty($products_data)) {
                break;
            }

            foreach ($products_data as $k => $v) {
                $products_data[$k] = $v['SNIZE_data'];
            }

            $last_id = $v['product_id'];
            $product_chunks = array_chunk($products_data, static::PRODUCTS_PER_REQUEST);

            foreach ($product_chunks as $encoded_products) {
                $totals = $this->getEncodedProductsSummary($encoded_products);
                $this->totals_count += $this->saveTotals($totals);

                if ($this->isTesting() || $this->isLastErrorFatal()) {
                    return $this->totals_count;
                }
            }
        }

        return $this->totals_count;
    }

    protected function fetchReviews(array $product_handles)
    {
        $feed = [
            'product_average' => [],
        ];
        $product_handle_str = '';

        $run_review = function($handles_str, array &$feed) {
            $feed_url = static::TOTALS_FEED_URL . '?' . http_build_query([
                'store_name'      => $this->engine_host,
                'product_handles' => $handles_str,
            ]);

            list($headers, $response) = fn_http_request('GET', $feed_url);

            if (!empty($headers['RESPONSE']) && self::isHttpHeader200($headers['RESPONSE'])) {
                $review_feed = json_decode($response, true);

                $this->debug(static::DEBUG_TYPE_REQUEST, $feed_url);
                $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
                $this->debug(static::DEBUG_TYPE_RESPONSE, $response);
                $this->debug(static::DEBUG_TYPE_PRODUCTS, $review_feed);

                if (!empty($review_feed) && !isset($review_feed['errors'])) {
                    $feed['product_average'] = array_merge($feed['product_average'], $review_feed['product_average']);

                } elseif (isset($review_feed['errors']) && $review_feed['errors'] !== 'Product not exist.') {
                    isset($feed['errors']) ?: $feed['errors'] = [];
                    $feed['errors'][] = $review_feed['errors'];
                    $feed['errors'] = array_unique($feed['errors']);
                }

            } elseif (!empty($headers['RESPONSE'])) {
                $this->log("Rivyo: Unable to fetch review for {$this->engine_data['engine_id']}. Response: {$headers['RESPONSE']}");
            }

            return true;
        };

        foreach ($product_handles as $handle) {
            if (mb_strlen($product_handle_str) + mb_strlen($handle) < self::MAX_FEED_PARAM_LEN) {
                $product_handle_str .= (!empty($product_handle_str) ? ',' : '') . $handle;

            } else {
                $run_review($product_handle_str, $feed);
                $product_handle_str = $handle;
            }
        }

        if (!empty($product_handle_str)) {
            $run_review($product_handle_str, $feed);
        }

        if (isset($feed['errors'])) {
            $feed['errors'] = implode(', ', $feed['errors']);
        }

        return $feed;
    }

    protected function getEncodedProductsSummary($encoded_products)
    {
        $totals = [];
        $product_handles = [];

        foreach ($encoded_products as $json_object) {
            $product_data = json_decode($json_object, true);
            $product_link = $product_data['link'];
            $product_id = $product_data['product_id'];

            if (empty($product_link)) {
                $this->log("Product link is empty for product {$product_id}");

                continue;
            }

            $product_link_parts = parse_url($product_link);
            $product_url_path = $product_link_parts['path'];

            if (strrpos($product_url_path, '/products/') !== false) {
                $exploded = explode('/products/', $product_url_path);
                $product_handles[$product_id] = $exploded[1];
            }
        }

        if (empty($product_handles)) {
            $this->log("Product handles is empty at all");

            return $totals;
        }

        $review_feed = $this->fetchReviews($product_handles);

        if (!empty($review_feed) && !isset($review_feed['errors'])) {
            foreach ($review_feed['product_average'] as $handle => $row) {
                $pid = array_search($handle, $product_handles);
                $totals[$pid] = [
                    'total_reviews' => $row['review_count'],
                    'reviews_average_score' => $row['review_average'],
                    'reviews_average_score_titles' => $this->getAverageScoreTitle($row['review_average']),
                ];
            }

        } elseif (isset($review_feed['errors']) && $review_feed['errors'] !== 'Product not exist.') {
            $this->log($review_feed['errors']);
            $this->last_error = $review_feed['errors'];
        }

        return $totals;
    }

    public function testConnect()
    {
        $url = static::TOTALS_FEED_URL . '?' . http_build_query([
                'store_name' => $this->engine_host
            ]);

        return  array_reduce(fn_http_headers($url), function($res, $item) {
            return $res | self::isHttpHeader200($item);
        }, false);
    }
}
