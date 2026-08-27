<?php
/**
 * Свой клиент API RetailCRM: только чтение.
 *
 * До сих пор мост ходил в CRM транспортом плагина Simla и его ключом. Это
 * связывало нас с чужим плагином и не давало ни своего журнала запросов,
 * ни возможности ограничить права ключа. Здесь — собственный клиент на
 * wp_remote_request с отдельным ключом из настроек кабинета.
 *
 * Главное свойство: клиент **не умеет писать**. Реализованы только методы
 * чтения, а любой другой вызов возвращает ошибку и уходит в журнал. Всё, что
 * меняет данные в CRM (покупатели, заказы, вступление в программу лояльности),
 * по-прежнему делает плагин Simla — двух пишущих в одну базу быть не должно.
 *
 * @package VL_Account
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ответ CRM в том же виде, что и у клиента Simla.
 */
class VL_Account_CRM_Response implements ArrayAccess {

	/**
	 * Код ответа HTTP.
	 *
	 * @var int
	 */
	protected $code = 0;

	/**
	 * Разобранное тело ответа.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Текст ошибки транспорта, если запрос не состоялся.
	 *
	 * @var string
	 */
	protected $error = '';

	/**
	 * Конструктор.
	 *
	 * @param int    $code  Код ответа.
	 * @param string $body  Тело ответа.
	 * @param string $error Ошибка транспорта.
	 */
	public function __construct( $code, $body = '', $error = '' ) {
		$this->code  = (int) $code;
		$this->error = (string) $error;

		$data = json_decode( (string) $body, true );

		$this->data = is_array( $data ) ? $data : array();
	}

	/**
	 * Запрос удался.
	 *
	 * @return bool
	 */
	public function isSuccessful() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->code > 0 && $this->code < 400;
	}

	/**
	 * Код ответа.
	 *
	 * @return int
	 */
	public function getStatusCode() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->code;
	}

	/**
	 * Текст ошибки от CRM или от транспорта.
	 *
	 * @return string
	 */
	public function getErrorString() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		if ( '' !== $this->error ) {
			return $this->error;
		}

		if ( isset( $this->data['errorMsg'] ) ) {
			return (string) $this->data['errorMsg'];
		}

		return '';
	}

	/**
	 * Всё тело ответа.
	 *
	 * @return array
	 */
	public function toArray() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->data;
	}

	/**
	 * Есть ли ключ в ответе.
	 *
	 * @param mixed $offset Ключ.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->data[ $offset ] );
	}

	/**
	 * Значение из ответа.
	 *
	 * @param mixed $offset Ключ.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return isset( $this->data[ $offset ] ) ? $this->data[ $offset ] : null;
	}

	/**
	 * Ответ только читается.
	 *
	 * @param mixed $offset Ключ.
	 * @param mixed $value  Значение.
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		// Ответ CRM неизменяем.
	}

	/**
	 * Ответ только читается.
	 *
	 * @param mixed $offset Ключ.
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		// Ответ CRM неизменяем.
	}
}

/**
 * Клиент API RetailCRM только на чтение.
 */
class VL_Account_CRM_Client {

	/**
	 * Адрес магазина без /api.
	 *
	 * @var string
	 */
	protected $base = '';

	/**
	 * Ключ API.
	 *
	 * @var string
	 */
	protected $key = '';

	/**
	 * Код магазина.
	 *
	 * @var string
	 */
	protected $site = '';

	/**
	 * Последние запросы (для экрана диагностики).
	 *
	 * @var array
	 */
	protected static $journal = array();

	/**
	 * Конструктор.
	 *
	 * @param string $url  Адрес CRM.
	 * @param string $key  Ключ API.
	 * @param string $site Код магазина.
	 */
	public function __construct( $url, $key, $site = '' ) {
		$this->base = rtrim( trim( (string) $url ), '/' );
		$this->key  = trim( (string) $key );
		$this->site = trim( (string) $site );
	}

	/* ------------------------------------------------------------------
	 * Чтение
	 * ------------------------------------------------------------------ */

	/**
	 * Права ключа.
	 *
	 * @return VL_Account_CRM_Response
	 */
	public function credentials() {
		// Права ключа лежат вне версии API: /api/credentials, а не /api/v5/...
		return $this->get( '/credentials', array(), false );
	}

	/**
	 * Код магазина для ключа.
	 *
	 * @return string
	 */
	public function getSingleSiteForKey() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		if ( '' !== $this->site ) {
			return $this->site;
		}

		$response = $this->credentials();

		if ( ! $response->isSuccessful() || ! $response->offsetExists( 'sitesAvailable' ) ) {
			return '';
		}

		$sites = (array) $response['sitesAvailable'];

		return 1 === count( $sites ) ? (string) reset( $sites ) : '';
	}

	/**
	 * Покупатель по идентификатору.
	 *
	 * @param mixed  $id Идентификатор.
	 * @param string $by externalId | id.
	 * @return VL_Account_CRM_Response
	 */
	public function customersGet( $id, $by = 'externalId' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		$params = array( 'by' => $by );

		// Магазин CRM принимает только здесь: в списках он не нужен, а на
		// части эндпоинтов лишний параметр приводит к ошибке.
		if ( '' !== $this->site ) {
			$params['site'] = $this->site;
		}

		return $this->get( '/customers/' . rawurlencode( (string) $id ), $params );
	}

	/**
	 * Список покупателей.
	 *
	 * @param array $filter Фильтр.
	 * @param int   $page   Страница.
	 * @param int   $limit  Размер страницы.
	 * @return VL_Account_CRM_Response
	 */
	public function customersList( $filter = array(), $page = null, $limit = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->get( '/customers', $this->paging( $filter, $page, $limit ) );
	}

	/**
	 * Список заказов.
	 *
	 * @param array $filter Фильтр.
	 * @param int   $page   Страница.
	 * @param int   $limit  Размер страницы.
	 * @return VL_Account_CRM_Response
	 */
	public function ordersList( $filter = array(), $page = null, $limit = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->get( '/orders', $this->paging( $filter, $page, $limit ) );
	}

	/**
	 * Справочник статусов заказов.
	 *
	 * @return VL_Account_CRM_Response
	 */
	public function statusesList() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->get( '/reference/statuses' );
	}

	/**
	 * Участия в программе лояльности.
	 *
	 * @param array $filter Фильтр.
	 * @param int   $limit  Размер страницы.
	 * @param int   $page   Страница.
	 * @return VL_Account_CRM_Response
	 */
	public function getLoyaltyAccountList( $filter = array(), $limit = null, $page = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->get( '/loyalty/accounts', $this->paging( $filter, $page, $limit ) );
	}

	/**
	 * Одно участие в программе лояльности.
	 *
	 * @param int $id Участие.
	 * @return VL_Account_CRM_Response
	 */
	public function getLoyaltyClientInfo( $id ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		return $this->get( '/loyalty/account/' . (int) $id );
	}

	/**
	 * История бонусов участия.
	 *
	 * @param int   $id     Участие.
	 * @param array $filter Фильтр.
	 * @param int   $limit  Размер страницы.
	 * @param int   $page   Страница.
	 * @return VL_Account_CRM_Response
	 */
	public function getClientBonusHistory( $id, $filter = array(), $limit = null, $page = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		$params       = $this->paging( $filter, $page, $limit );
		$params['id'] = (int) $id;

		return $this->get( '/loyalty/account/' . (int) $id . '/bonus/operations', $params );
	}

	/**
	 * Баллы в ожидании активации или на сгорании.
	 *
	 * @param int    $id     Участие.
	 * @param string $status waiting_activation | burn_soon.
	 * @param array  $filter Фильтр.
	 * @param int    $limit  Размер страницы.
	 * @param int    $page   Страница.
	 * @return VL_Account_CRM_Response
	 */
	public function getDetailClientBonus( $id, $status, $filter = array(), $limit = null, $page = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- совместимость с клиентом Simla.
		$params           = $this->paging( $filter, $page, $limit );
		$params['id']     = (int) $id;
		$params['status'] = (string) $status;

		return $this->get(
			'/loyalty/account/' . (int) $id . '/bonus/' . rawurlencode( (string) $status ) . '/details',
			$params
		);
	}

	/* ------------------------------------------------------------------
	 * Запрет записи
	 * ------------------------------------------------------------------ */

	/**
	 * Любой неизвестный вызов — это попытка записи.
	 *
	 * Клиент умышленно умеет только читать: так ошибка в нашем коде не может
	 * испортить данные в CRM. Вызов не выполняется, а попадает в журнал.
	 *
	 * @param string $method    Метод.
	 * @param array  $arguments Аргументы.
	 * @return VL_Account_CRM_Response
	 */
	public function __call( $method, $arguments ) {
		self::log_request( 'BLOCKED', $method, 0, 0.0 );

		vlacc_log(
			'[CRM] Запись в CRM через свой ключ запрещена',
			array( 'метод' => $method )
		);

		return new VL_Account_CRM_Response( 405, '', __( 'Свой ключ API работает только на чтение.', 'vl-account' ) );
	}

	/* ------------------------------------------------------------------
	 * Транспорт
	 * ------------------------------------------------------------------ */

	/**
	 * Собрать параметры постранички так же, как это делает клиент Simla.
	 *
	 * @param array $filter Фильтр.
	 * @param int   $page   Страница.
	 * @param int   $limit  Размер страницы.
	 * @return array
	 */
	protected function paging( $filter, $page, $limit ) {
		$params = array();

		if ( $filter ) {
			$params['filter'] = $filter;
		}

		if ( $page ) {
			$params['page'] = (int) $page;
		}

		if ( $limit ) {
			$params['limit'] = (int) $limit;
		}

		return $params;
	}

	/**
	 * GET-запрос к API.
	 *
	 * @param string $path      Путь.
	 * @param array  $params    Параметры.
	 * @param bool   $versioned Запрос к версии API (/api/v5) или к корню (/api).
	 * @return VL_Account_CRM_Response
	 */
	protected function get( $path, $params = array(), $versioned = true ) {
		if ( '' === $this->key ) {
			return new VL_Account_CRM_Response( 0, '', __( 'Не заполнен ключ API.', 'vl-account' ) );
		}

		$params['apiKey'] = $this->key;

		$prefix = $versioned ? '/api/v5' : '/api';
		$url    = $this->base . $prefix . $path . '?' . http_build_query( $params, '', '&' );
		$start = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$took = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			self::log_request( 'GET', $path, 0, $took, $response->get_error_message() );

			return new VL_Account_CRM_Response( 0, '', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		self::log_request( 'GET', $path, $code, $took );

		return new VL_Account_CRM_Response( $code, $body );
	}

	/* ------------------------------------------------------------------
	 * Журнал запросов
	 * ------------------------------------------------------------------ */

	/**
	 * Записать запрос в журнал.
	 *
	 * @param string $method Метод HTTP или BLOCKED.
	 * @param string $path   Путь.
	 * @param int    $code   Код ответа.
	 * @param float  $took   Время, сек.
	 * @param string $error  Ошибка.
	 */
	protected static function log_request( $method, $path, $code, $took, $error = '' ) {
		self::$journal[] = array(
			'time'   => current_time( 'mysql' ),
			'method' => $method,
			'path'   => $path,
			'code'   => (int) $code,
			'took'   => round( (float) $took, 3 ),
			'error'  => $error,
		);

		// Держим последние полсотни: журнал живёт в пределах запроса.
		if ( count( self::$journal ) > 50 ) {
			array_shift( self::$journal );
		}

		if ( ! VL_Account_Settings::get( 'crm_api_log', 0 ) ) {
			return;
		}

		vlacc_log(
			'[CRM] ' . $method . ' ' . $path,
			array(
				'код'   => $code,
				'время' => round( (float) $took, 3 ),
				'ошибка' => $error,
			)
		);
	}

	/**
	 * Запросы, сделанные в этом запросе страницы.
	 *
	 * @return array
	 */
	public static function journal() {
		return self::$journal;
	}
}
