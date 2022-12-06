<?php

namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Conversio extends BaseReviewsProvider
{
    const TOTALS_FEED_URL = 'https://commerce.campaignmonitor.com/api/v1/product-reviews';
    const REVIEWS_PARAMS_VISIBLE = true;
    const PRODUCTS_PER_REQUEST = 50;

    protected $product_reviews_totals;
    protected $required_engine_data = [
        'conversio_api_key'
    ];

    public function getAllProductReviewsTotals()
    {
        $products = [];
        $params = [
            'page' => 1
        ];

        do {
            $results = $this->getProductsReviews($params);
            $params['page'] += 1;

            if (!empty($results['data'])) {
                foreach ($results['data'] as $review) {
                    $products[$review['productId']]['rating_scores'][] = $review['rating'];
                }

                if ($this->isTesting()) {
                    break;
                }
            }
        } while (isset($results['meta']['nextPage']));

        $totals = array_map(function ($product) {
            $total_reviews = count($product['rating_scores']);
            $reviews_average_score = array_sum($product['rating_scores']) / $total_reviews;

            return [
                'total_reviews' => $total_reviews,
                'reviews_average_score' => $reviews_average_score,
                'reviews_average_score_titles' => self::getAverageScoreTitle($reviews_average_score),
            ];
        }, $products);

        return $this->saveTotals($totals);
    }

    private function getProductsReviews($params)
    {
        $url = static::TOTALS_FEED_URL . '?' . http_build_query([
                'visible' => static::REVIEWS_PARAMS_VISIBLE,
                'limit' => static::PRODUCTS_PER_REQUEST,
                'page' => $params['page']
            ]);

        $http_headers = [
            "X-ApiKey: " . $this->getEngineData('conversio_api_key'),
        ];

        $this->debug(static::DEBUG_TYPE_REQUEST, sprintf(
            "curl -gs '%s' -H 'X-ApiKey: %s'",
            $url, $this->getEngineData('conversio_api_key')
        ));

        $results = [];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0');

            if (!empty($http_headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
            }

            $content = curl_exec($ch);
            $headers = curl_getinfo($ch);
            $errmsg  = curl_error($ch);
            $errno   = curl_errno($ch);
            curl_close($ch);

            if (!empty($errmsg)) {
                throw $this->makeException("CURL error: [{$errno}] {$errmsg}", $headers['http_code']);
            }

            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $content);

            if (!empty($content) && !empty($headers['header_size'])) {
                $results = json_decode(substr($content, $headers['header_size']), true);
                $this->debug(static::DEBUG_TYPE_PRODUCTS, $results);
            }

            if (!empty($results['errors'])) {
                throw $this->makeException($results['errors']);
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }

        return $results;
    }
}
