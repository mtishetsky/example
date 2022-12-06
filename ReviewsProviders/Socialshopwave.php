<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Socialshopwave extends BaseReviewsProvider
{
    const TOTALS_FEED_URL = 'http://www.socialshopwave.com/lite/searchanise/reviews';

    protected function getFeedUrl()
    {
        return static::TOTALS_FEED_URL . '?' . http_build_query([
                'shop' => $this->getEngineData('name'),
            ]);
    }

    public function getAllProductReviewsTotals()
    {
        list($headers, $response) = fn_http_request('GET', $this->getFeedUrl());

        $this->debug(static::DEBUG_TYPE_REQUEST, $this->getFeedUrl());
        $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        if (empty($response)) {
            $this->log(self::ERROR_EMPTY_SUMMARY_RESPONSE);

            return $this->totals_count;
        }

        $feed = json_decode($response, true);

        if (is_null($feed)) {
            $this->log(self::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE, $response);

            return $this->totals_count;
        }

        $this->debug(static::DEBUG_TYPE_PRODUCTS, $feed);

        if (!is_array($feed) && empty($feed)) {
            $this->log('Empty feed', $response);

            return $this->totals_count;
        }

        if (isset($feed['success']) && $feed['success'] == false) {
            $this->log('Feed error', $response);
            return $this->totals_count;
        }

        $totals = [];

        foreach ($feed as $row) {
            $totals[$row['product_id']] = [
                'total_reviews' => $row['total_reviews'],
                'reviews_average_score' => $row['review_average'],
                'reviews_average_score_titles' => self::getAverageScoreTitle($row['review_average']),
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
