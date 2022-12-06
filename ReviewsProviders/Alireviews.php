<?php
/***************************************************************************
*                                                                          *
*    Copyright (c) 2004 Simbirsk Technologies Ltd. All rights reserved.    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Alireviews extends BaseReviewsProvider
{
    const TOTALS_FEED_URL  = 'https://alireviews.fireapps.io/comment/get_summary_star_collection';
    const TEST_CONNECT_URL = 'https://alireviews.fireapps.io/api/shops/';
    const PRODUCTS_PER_REQUEST = 100;

    protected $engine_host;
    protected $required_engine_data = [
        'shopify_access_token',
        'name'
    ];
    protected $fatal_errors = [
        'Cannot find review' => 1,
    ];

    public function __construct($engine_data)
    {
        parent::__construct($engine_data);

        $parsed_engine_name = parse_url($engine_data['name']);

        if (empty($parsed_engine_name['host'])) {
            throw $this->makeException(strtr('Failed to parse hostname from :name', [
                'name' => $engine_data['name']
            ]));
        }

        $this->engine_host = $parsed_engine_name['host'];
    }

    protected function getProductsSummary($product_ids)
    {
        $feed_url = self::TOTALS_FEED_URL . '?' . http_build_query([
                'shop_domain' => $this->engine_host,
            ]);

        foreach ($product_ids as $id) {
            $feed_url .= '&product_ids[]=' . $id;
        }

        list($headers, $response) = fn_http_request('GET', $feed_url);

        $this->debug(static::DEBUG_TYPE_REQUEST, $feed_url);
        $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        $result = [];

        if (empty($headers['RESPONSE']) || !self::isHttpHeader200($headers['RESPONSE'])) {
            $this->last_error = empty($headers['RESPONSE']) ? static::ERROR_EMPTY_RESPONSE_HEADER : static::ERROR_RESPONSE_HEADER_NOT_OK;
            $this->log($this->last_error, $feed_url, $headers);

            return $result;
        }

        $products_summary = @json_decode($response, true);

        if (empty($products_summary)) {
            $this->last_error = is_null($products_summary) ? static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : self::ERROR_EMPTY_SUMMARY_RESPONSE;
            $this->log($this->last_error, $response);

            return $result;
        }

        if (!empty($products_summary['avg_star']) && !empty($products_summary['total_review'])) {
            $this->debug(static::DEBUG_TYPE_PRODUCTS, $products_summary);

            foreach ($products_summary['avg_star'] as $product_id => $avg_score) {
                $result[$product_id] = [
                    'total_reviews' => 0,
                    'reviews_average_score' => $avg_score,
                    'reviews_average_score_titles' => self::getAverageScoreTitle($avg_score),
                ];
            }

            foreach ($products_summary['total_review'] as $product_id => $review_count) {
                $result[$product_id]['total_reviews'] = $review_count;
            }

        } elseif (isset($products_summary['message'])) {
            $this->log('API error', $products_summary);
            $this->last_error = $products_summary['message'];
        }

        return $result;
    }

    public function testConnect()
    {
        return array_reduce(fn_http_headers(static::TEST_CONNECT_URL . $this->engine_host), function($res, $item) {
            return $res | self::isHttpHeader200($item);
        }, false);
    }
}
