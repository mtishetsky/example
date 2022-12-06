<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Okendo extends BaseReviewsProvider
{
    const TOTALS_FEED_URL = 'https://s3-us-west-2.amazonaws.com/prod-1-reviews-product-reviews-feed/:user_id:/product-review-aggregate-feed.json';

    protected $required_engine_data = [
        'okendo_user_id'
    ];

    protected function getFeedUrl()
    {
        return strtr(static::TOTALS_FEED_URL, [
            ':user_id:' => $this->getEngineData('okendo_user_id'),
        ]);
    }

    public function getAllProductReviewsTotals()
    {
        list($headers, $response) = fn_http_request('GET', $this->getFeedUrl());
        $feed = @json_decode($response, true);

        $this->debug(static::DEBUG_TYPE_REQUEST, $this->getFeedUrl());
        $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        if (empty($feed)) {
            $this->last_error = is_null($feed) ? static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : static::ERROR_EMPTY_SUMMARY_RESPONSE;
            $this->log($this->last_error);

            return $this->totals_count;
        }

        $totals = [];

        foreach ($feed as $row) {
            $totals[$row['product_id']] = [
                'total_reviews' => $row['count'],
                'reviews_average_score' => $row['rating'],
                'reviews_average_score_titles' => self::getAverageScoreTitle($row['rating']),
            ];
        }

        return $this->saveTotals($totals);
    }

    public function testConnect()
    {
        return array_reduce(fn_http_headers($this->getFeedUrl()), function($res, $item) {
            return $res | self::isHttpHeader200($item);
        }, false);
    }
}
