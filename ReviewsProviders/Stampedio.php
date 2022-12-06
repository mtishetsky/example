<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Stampedio extends BaseReviewsProvider
{
    const MAX_REVIEW_PAGES = 10000;
    const TOTALS_FEED_URL  = 'https://stamped.io/api/widget/badges';
    const REVIEWS_FEED_URL = 'http://stamped.io/api/widget/reviews';
    const TEST_CONNECT_URL = 'http://stamped.io/api/auth/check';
    const PRODUCTS_PER_REQUEST = 32;
    const REVIEWS_PER_REQUEST = 100;

    protected $publicApiKey;
    protected $required_engine_data = [
        'stampedio_client_id',
        'stampedio_secret_key',
        'stampedio_domain',
    ];

    protected function getProductsSummary($product_ids)
    {
        if (!is_array($product_ids)) {
            $product_ids = [$product_ids];
        }

        $data = json_encode([
            'apiKey' => $this->getEngineData('stampedio_client_id'),
            'storeUrl' => $this->getEngineData('stampedio_domain'),
            'productIds' => array_map(function($id) {
                return (object)['productId' => (int)$id];
            }, $product_ids),
        ]);

        $this->debug(static::DEBUG_TYPE_REQUEST, sprintf(
            "curl -gs -X POST '%s' -d '%s' -H 'Content-type: application/json'",
            static::TOTALS_FEED_URL, $data
        ));

        $sleep = 2;

        while (1) {
            sleep($sleep);

            list($headers, $response) = fn_https_request('POST', static::TOTALS_FEED_URL, $data, '&', '', 'application/json');

            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            if (empty($headers['RESPONSE']) || !self::isHttpHeader200($headers['RESPONSE'])) {
                $this->last_error = empty($headers['RESPONSE']) ? static::ERROR_EMPTY_RESPONSE_HEADER : static::ERROR_RESPONSE_HEADER_NOT_OK;
                $sleep += 2;

                if ($this->proceedRetry($this->last_error . ' when getting totals', ['args' => [$headers]])) {
                    continue;
                }

                return [];
            }

            $products_summary = @json_decode($response, true);

            if (empty($products_summary)) {
                $this->last_error = is_null($products_summary) ? static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : static::ERROR_EMPTY_SUMMARY_RESPONSE;
                $sleep += 2;

                if ($this->proceedRetry($this->last_error . ' when getting totals', ['args' =>[$response]])) {
                    continue;
                }

                return [];
            }

            $sleep < 3 ?: $sleep -= 2;

            $this->last_error = null;
            $this->resetRetry();

            break;
        }

        $this->debug(static::DEBUG_TYPE_PRODUCTS, $products_summary);

        $result = [];

        foreach ($products_summary as $row) {
            $this->debug(static::DEBUG_TYPE_PRODUCT, $row);

            $result[$row['productId']] = [
                'total_reviews' => $row['count'],
                'reviews_average_score' => round($row['rating'], 2),
                'reviews_average_score_titles' => self::getAverageScoreTitle($row['rating']),
            ];
        }

        return $result;
    }

    public function getAllProductReviews()
    {
        $this->last_error = null;

        if (!$this->testConnect() || empty($this->publicApiKey)) {
            return [];
        }

        $product_reviews = [];
        $products_linked_by_sku = [];

        $params = [
            'page' => 0,
            'take' => static::REVIEWS_PER_REQUEST,
            'storeUrl' => $this->getEngineData('stampedio_domain'),
            'apiKey' => $this->publicApiKey,
            'minrating' => 1,
        ];

        while (true) {
            $params['page'] += 1;
            $response = $this->getReviewsResponse($params);

            if (!is_array($response)) {
                if ($this->proceedRetry($this->last_error . ' when getting reviews', ['args' => [$response]])) {
                    continue;
                }

                return 0;
            }

            $this->last_error = null;
            $this->resetRetry();

            foreach ($response['data'] as $review) {
                if (!isset($review['productId']) && !isset($review['productSKU'])) {
                    continue;
                }

                if (!isset($product_reviews[$review['productId']])) {
                    $product_reviews[$review['productId']] = [];
                }

                $product_reviews[$review['productId']][] = [
                    'title' => isset($review['reviewTitle']) ? $review['reviewTitle'] : '',
                    'body' => isset($review['reviewMessage']) ? $review['reviewMessage'] : '',
                ];

                if (isset($review['productSKU'])) {
                    $sku = $review['productSKU'];

                    if (!isset($products_linked_by_sku[$sku])) {
                        $products_linked_by_sku[$sku] = [];
                    }

                    if (!in_array($review['productId'], $products_linked_by_sku[$sku])) {
                        $products_linked_by_sku[$sku][] = $review['productId'];
                    }
                }
            }

            if ($this->isTesting() || $params['page'] * $params['take'] >= $response['totalAll']) {
                break;
            }
        }

        foreach ($products_linked_by_sku as $sku => $product_ids) {
            if (count($product_ids) < 2) {
                continue;
            }

            $sku_reviews = [];

            foreach ($product_ids as $product_id) {
                if (!isset($product_reviews[$product_id])) {
                    continue;
                }

                $sku_reviews = array_merge($sku_reviews, $product_reviews[$product_id]);
            }

            if (empty($sku_reviews)) {
                continue;
            }

            foreach ($product_ids as $product_id) {
                $product_reviews[$product_id] = $sku_reviews;
            }
        }

        return $this->saveReviews($product_reviews);
    }

    public function testConnect()
    {
        list($headers, $response) = fn_http_request('POST', static::TEST_CONNECT_URL, [], [], [
            $this->getEngineData('stampedio_client_id'),
            $this->getEngineData('stampedio_secret_key')
        ]);

        $this->debug(static::DEBUG_TYPE_REQUEST, sprintf(
            "stampedio_domain = %s ; curl -X POST -Lgs '%s' -u '%s:%s' -H 'Content-length:0'",
            $this->getEngineData('stampedio_domain'),
            static::TEST_CONNECT_URL,
            $this->getEngineData('stampedio_client_id'),
            $this->getEngineData('stampedio_secret_key')
        ));
        $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        if (empty($response) || empty($headers['RESPONSE']) || !self::isHttpHeader200($headers['RESPONSE'])) {
            return false;
        }

        $response = json_decode($response, true);

        if (empty($response['storesList']) || !is_array($response['storesList'])) {
            return false;
        }

        $lowercase_domain = strtolower($this->getEngineData('stampedio_domain'));

        foreach ($response['storesList'] as $store_data) {
            if ($lowercase_domain == strtolower($store_data['shopUrl'])) {
                $this->publicApiKey = $store_data['apiKeyPublic'];
                break;
            }

            if (!empty($store_data['shopUrl2']) && $lowercase_domain == strtolower($store_data['shopUrl2'])) {
                $this->publicApiKey = $store_data['apiKeyPublic'];
                break;
            }
        }

        return !empty($this->publicApiKey);
    }

    protected function getReviewsResponse($params)
    {
        $feed_url = static::REVIEWS_FEED_URL . '?' . http_build_query($params);

        list($headers, $response) = fn_http_request('GET', $feed_url, [], [], []);

        $this->debug(static::DEBUG_TYPE_REQUEST, $feed_url);
        $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        if (empty($headers['RESPONSE']) || !self::isHttpHeader200($headers['RESPONSE'])) {
            $this->last_error = empty($headers['RESPONSE']) ? self::ERROR_EMPTY_RESPONSE_HEADER : self::ERROR_RESPONSE_HEADER_NOT_OK;
            $this->log($this->last_error, $feed_url, $headers);

            return false;
        }

        $response = @json_decode($response, true);

        if (empty($response)) {
            $this->last_error = is_null($response) ? self::ERROR_UNABLE_DECODE_REVIEWS_RESPONSE : self::ERROR_EMPTY_REVIEWS_RESPONSE;
        } elseif (empty($response['totalAll'])) {
            $this->last_error = 'Reviews totalAll is empty, unable to proceed';
        } elseif (!is_array($response['data']) || empty($response['data'])) {
            $this->last_error = 'Reviews data is empty, unable to proceed';
        }

        if (!empty($this->last_error)) {
            $this->log($this->last_error, $feed_url, $response);

            return false;
        }

        return $response;
    }
}
