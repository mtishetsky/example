<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Judgeme extends BaseReviewsProvider
{
    const PRODUCTS_PER_REQUEST = 10;
    const TOTALS_FEED_URL = 'https://judge.me/api/reviews/aggregate_feed';
    const REVIEWS_FEED_URL = 'https://judge.me/api/v1/reviews';

    protected $required_engine_data = [
        'judgeme_domain'
    ];
    protected $fatal_errors = [
        'Shop does not enable review aggregate feed.' => 1,
    ];

    protected $reviews_count = 0;

    public function getAllProductReviewsTotals()
    {
        $feed_url = static::TOTALS_FEED_URL . '?' . http_build_query([
                'shop_domain' => $this->getEngineData('judgeme_domain'),
            ]);

        $this->debug(static::DEBUG_TYPE_REQUEST, $feed_url);

        while(1) {
            list($headers, $response) = fn_http_request('GET', $feed_url);
            $feed = @json_decode($response, true);

            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            if (empty($feed)) {
                $this->last_error = is_null($feed) ? static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : static::ERROR_EMPTY_SUMMARY_RESPONSE;
            } elseif (!empty($feed['error'])) {
                $this->last_error = $feed['error'];
            }

            if (!empty($this->last_error)) {
                if ($this->proceedRetry($this->last_error)) {
                    continue;
                }

                return $this->totals_count;
            }

            $this->last_error = null;
            $this->resetRetry();

            break;
        }

        $this->debug(static::DEBUG_TYPE_PRODUCTS, $feed);

        $totals = [];

        foreach ($feed as $row) {
            $product_id = !empty($row['skus']) ? $row['skus'] : $row['sku'];
            $totals[$product_id] = [
                'total_reviews' => $row['total_review'],
                'reviews_average_score' => $row['review_average'],
                'reviews_average_score_titles' => self::getAverageScoreTitle($row['review_average']),
            ];
        }

        return $this->saveTotals($totals);
    }

    public function getAllProductReviews()
    {
        $this->last_error = null;
        $page = 1;

        while (true) {
            $feed_url = static::REVIEWS_FEED_URL . '?' . http_build_query([
                    'shop_domain' => $this->getEngineData('judgeme_domain'),
                    'api_token' => $this->getEngineData('judgeme_api_token'),
                    'per_page' => static::PRODUCTS_PER_REQUEST,
                    'page' => $page++
                ]);

            list($headers, $response) = fn_http_request('GET', $feed_url);

            $this->debug(static::DEBUG_TYPE_REQUEST, $feed_url);
            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            $feed = @json_decode($response, true);

            if (is_null($feed)) {
                $this->last_error = self::ERROR_UNABLE_DECODE_REVIEWS_RESPONSE;
            } elseif (empty($feed)) {
                $this->last_error = self::ERROR_EMPTY_REVIEWS_RESPONSE;
            } elseif (!empty($feed['error'])) {
                $this->last_error = $feed['error'];
            }

            if (!empty($this->last_error)) {
                $this->log($this->last_error);

                return $this->reviews_count;
            }

            $this->debug(static::DEBUG_TYPE_PRODUCTS, $feed);

            if (empty($feed['reviews'])) {
                break;
            }

            $product_reviews = [];

            foreach ($feed['reviews'] as $review) {
                if (!empty($review['product_external_id']) && empty($review['hidden'])) {
                    $product_reviews[$review['product_external_id']][] = [
                        'title' => $review['title'],
                        'body' => $review['body'],
                    ];
                }
            }

            $this->reviews_count += $this->saveReviews($product_reviews);

            if ($this->isTesting()) {
                break;
            }
        }

        return $this->reviews_count;
    }

    public function testConnect()
    {
        $feed_url = static::TOTALS_FEED_URL . '?' . http_build_query([
                'shop_domain' => $this->getEngineData('judgeme_domain'),
            ]);

        return array_reduce(fn_http_headers($feed_url), function($res, $item) {
            return $res | (self::isHttpHeader200($item) || preg_match('/302 Found/', $item));
        }, false);
    }
}
