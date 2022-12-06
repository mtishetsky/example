<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Shopperapproved extends BaseReviewsProvider
{
    const TOTALS_FEED_URL  = 'https://api.shopperapproved.com/aggregates/products/';

    protected $required_engine_data = [
        'shopperapproved_site_id',
        'shopperapproved_token',
    ];

    public function testConnect()
    {
        $url = static::TOTALS_FEED_URL . $this->getEngineData('shopperapproved_site_id');

        list($headers, $response) = fn_https_request('GET', $url, [
            'token' => $this->getEngineData('shopperapproved_token'),
        ]);

        return !empty($headers['RESPONSE']) && self::isHttpHeader200($headers['RESPONSE']);
    }

    public function getAllProductReviewsTotals()
    {
        $totals = [];

        foreach ([true, false] as $by_match_key) {
            $all_summary = $this->getSummary($by_match_key);

            $summary = $all_summary['product_totals'] ?? [];

            foreach ($summary as $product_id => $reviews_summary) {
                // Shopper Approved API provides product id in different formats across different stores:
                // it can be '{product_id}' or 'shopify_US_{product_id}'.
                $product_id = (int) str_replace('shopify_US_', '', $product_id);

                if (empty($product_id)) {
                    continue;
                }

                $totals[$product_id] = [
                    'total_reviews' => $reviews_summary['total_reviews'],
                    'reviews_average_score' => $reviews_summary['average_rating'],
                    'reviews_average_score_titles' => self::getAverageScoreTitle($reviews_summary['average_rating']),
                ];
            }

            if (!empty($totals)) {
                break;
            }
        }

        return $this->saveTotals($totals);
    }

    protected function getSummary($by_match_key)
    {
        $siteid = $this->getEngineData('shopperapproved_site_id');

        $params = [
            'token' => $this->getEngineData('shopperapproved_token'),
            'by_match_key' => $by_match_key,
            'xml' => false,
            'fastmode' => false,
        ];

        $url = static::TOTALS_FEED_URL . $siteid;

        $this->debug(static::DEBUG_TYPE_REQUEST, $url . '?' . http_build_query($params));

        while (1) {
            list($headers, $response) = fn_https_request('GET', $url, $params);

            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            if (empty($headers['RESPONSE']) || !self::isHttpHeader200($headers['RESPONSE'])) {
                $this->last_error = empty($headers['RESPONSE']) ? self::ERROR_EMPTY_RESPONSE_HEADER : self::ERROR_RESPONSE_HEADER_NOT_OK;

                if ($this->proceedRetry($this->last_error, ['args' => [$headers]])) {
                    continue;
                }

                return [];
            }

            $summary = @json_decode($response, true);

            if (empty($summary)) {
                $this->last_error = is_null($summary) ? self::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : self::ERROR_EMPTY_SUMMARY_RESPONSE;

                if ($this->proceedRetry($this->last_error, ['args' => [$response]])) {
                    continue;
                }

                return [];
            }

            $this->last_error = null;
            $this->resetRetry();

            break;
        }

        $this->debug(static::DEBUG_TYPE_PRODUCTS, $summary);

        return $summary;
    }

}
