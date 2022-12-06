<?php
namespace Core\ReviewsProviders;

use Registry;

if (!defined('AREA') ) { die('Access denied'); }

class YotpoV2 extends BaseReviewsProvider
{
    const PRODUCTS_PER_REQUEST = 200;

    protected $api_url = 'https://developers.yotpo.com/v2';
    protected $application_id;
    protected $application_secret;
    protected $redirect_uri;
    protected $required_engine_data = [
        'yotpo_v2_account_id',
        'yotpo_v2_access_token',
    ];

    public function __construct($engine_data)
    {
        parent::__construct($engine_data);

        $yotpo_config = Registry::get('config.yotpo');

        $this->application_id = $yotpo_config['application_id'];
        $this->application_secret = $yotpo_config['application_secret'];
        $this->redirect_uri = $yotpo_config['redirect_uri'];
    }

    public function getAllProductReviewsTotals()
    {
        $page = $total = 0;

        while (1) {
            $url = join('/', [
                $this->api_url,
                $this->getEngineData('yotpo_v2_account_id'),
                'products'
            ]);

            $data = [
                'access_token' => $this->getEngineData('yotpo_v2_access_token'),
                'page' => $page + 1,
                'count' => static::PRODUCTS_PER_REQUEST,
            ];

            [$headers, $response] = fn_https_request('GET', $url, $data, '&', '', 'application/json');

            $this->debug(static::DEBUG_TYPE_REQUEST, sprintf(
                "curl -gs '%s?%s' -H 'Content-type: application/json'",
                $url, http_build_query($data)
            ));
            $this->debug(static::DEBUG_TYPE_HEADERS, $headers);
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            if (empty($headers['RESPONSE'])){
                if ($this->proceedRetry(static::ERROR_EMPTY_RESPONSE_HEADER)) {
                    continue;
                }

                break;
            }

            $this->resetRetry(static::ERROR_EMPTY_RESPONSE_HEADER);

            if (!self::isHttpHeader200($headers['RESPONSE'])) {
                if ($this->proceedRetry(static::ERROR_RESPONSE_HEADER_NOT_OK, ['args' => [$headers]])) {
                    continue;
                }

                break;
            }

            $this->resetRetry(static::ERROR_RESPONSE_HEADER_NOT_OK);

            $response = json_decode($response, true);

            if (empty($response)) {
                $error = is_null($response) ? static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE : static::ERROR_EMPTY_SUMMARY_RESPONSE;

                if ($this->proceedRetry($error, ['args' => [$headers, $response]])) {
                    continue;
                }

                break;
            }

            $this->resetRetry(static::ERROR_UNABLE_DECODE_SUMMARY_RESPONSE);
            $this->resetRetry(static::ERROR_EMPTY_SUMMARY_RESPONSE);

            if (empty($response['products']['products'])) {
                $this->log('Response contains no products', $response);
                break;
            }

            $this->debug(static::DEBUG_TYPE_PRODUCTS, $response);

            $totals = [];

            foreach ($response['products']['products'] as $product) {
                if (empty($product['total_reviews']) || empty($product['external_product_id']) || 0 == intval($product['external_product_id'])) {
                    continue;
                }

                $totals[$product['external_product_id']] = array(
                    'total_reviews' => $product['total_reviews'],
                    'reviews_average_score' => $product['average_score'],
                    'reviews_average_score_titles' => self::getAverageScoreTitle($product['average_score']),
                );
            }

            $this->totals_count += $this->saveTotals($totals);
            $products_count = count($response['products']['products']);

            if ($this->isTesting() || $products_count > 0 && $products_count < static::PRODUCTS_PER_REQUEST) {
                return $this->totals_count;
            }

            if (isset($response['pagination'])) {
                $pagination = $response['pagination'];
                if (isset($pagination['page']) && isset($pagination['count']) && isset($pagination['total'])) {
                    $page = $pagination['page'];
                    $count = $pagination['count'];
                    $total = $pagination['total'];

                    if ($page * $count > $total) {
                        break;
                    }

                    continue;
                }
            }

            $page = $page + 1;
        }

        return $this->totals_count;
    }

    public function getAccessToken($code = '')
    {
        [$headers, $response] = fn_https_request(
            'POST',
            $this->api_url . '/oauth2/token',
            json_encode(array(
                'grant_type' => 'authorization_code',
                'client_id' => $this->application_id,
                'client_secret' => $this->application_secret,
                'redirect_uri' => $this->redirect_uri,
                'code' => $code,
            )),
            '&',
            '',
            'application/json'
        );

        if (!empty($headers['RESPONSE']) && self::isHttpHeader200($headers['RESPONSE'])) {
            $response = json_decode($response, true);

            if (!empty($response['access_token'])) {
                return $response['access_token'];
            }

            $this->log('Error getting access token', $headers, $response);
        }

        return false;
    }

    public static function getName()
    {
        return 'Yotpo';
    }
}
