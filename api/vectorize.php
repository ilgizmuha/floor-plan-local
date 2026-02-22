<?php
/**
 * Локальный API: отправка изображения в Vectorizer.AI и возврат SVG (JSON).
 * Запуск: из корня floor-plan-local выполнить: php -S localhost:8080
 * Открыть: http://localhost:8080
 */
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Access-Control-Allow-Origin: *' );

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	echo json_encode( array( 'error' => 'Только POST.' ) );
	exit;
}

$config_path = dirname( __DIR__ ) . '/config.php';
if ( ! file_exists( $config_path ) ) {
	echo json_encode( array( 'error' => 'Файл config.php не найден.' ) );
	exit;
}
include $config_path;

if ( empty( $vectorizer_username ) || empty( $vectorizer_password ) ) {
	echo json_encode( array( 'error' => 'Укажите логин и пароль Vectorizer.AI в config.php или config.local.php.' ) );
	exit;
}

if ( empty( $_FILES['image'] ) || $_FILES['image']['error'] !== UPLOAD_ERR_OK ) {
	$err = isset( $_FILES['image']['error'] ) ? $_FILES['image']['error'] : 'Файл не загружен.';
	$messages = array(
		UPLOAD_ERR_INI_SIZE   => 'Файл слишком большой (ini).',
		UPLOAD_ERR_FORM_SIZE  => 'Файл слишком большой.',
		UPLOAD_ERR_PARTIAL    => 'Загрузка не завершена.',
		UPLOAD_ERR_NO_FILE    => 'Файл не выбран.',
		UPLOAD_ERR_NO_TMP_DIR => 'Нет временной папки.',
		UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл.',
		UPLOAD_ERR_EXTENSION => 'Расширение остановило загрузку.',
	);
	$msg = isset( $messages[ $err ] ) ? $messages[ $err ] : 'Ошибка загрузки: ' . $err;
	echo json_encode( array( 'error' => $msg ) );
	exit;
}

$tmp = $_FILES['image']['tmp_name'];
$name = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $_FILES['image']['name'] );
$mime = $_FILES['image']['type'] ?: 'image/jpeg';
if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/jpg', 'image/gif' ), true ) ) {
	$mime = 'image/jpeg';
}

$content = file_get_contents( $tmp );
if ( $content === false ) {
	echo json_encode( array( 'error' => 'Не удалось прочитать загруженный файл.' ) );
	exit;
}

$boundary = bin2hex( random_bytes( 12 ) );
$body  = '--' . $boundary . "\r\n";
$body .= 'Content-Disposition: form-data; name="image"; filename="' . $name . '"' . "\r\n";
$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
$body .= $content . "\r\n";
$body .= '--' . $boundary . '--' . "\r\n";

$ch = curl_init( 'https://vectorizer.ai/api/v1/vectorize' );
curl_setopt_array( $ch, array(
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => $body,
	CURLOPT_HTTPHEADER     => array(
		'Authorization: Basic ' . base64_encode( $vectorizer_username . ':' . $vectorizer_password ),
		'User-Agent: FloorPlanLocal/1.0',
		'Content-Type: multipart/form-data; boundary=' . $boundary,
		'Content-Length: ' . strlen( $body ),
	),
	CURLOPT_RETURNTRANSFER  => true,
	CURLOPT_TIMEOUT         => 120,
	CURLOPT_FOLLOWLOCATION  => true,
	CURLOPT_SSL_VERIFYPEER  => true,
) );
$response = curl_exec( $ch );
$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$err_no   = curl_errno( $ch );
$err_msg  = curl_error( $ch );
curl_close( $ch );

if ( $err_no ) {
	echo json_encode( array( 'error' => 'Ошибка подключения: ' . $err_msg ) );
	exit;
}

if ( $code !== 200 ) {
	$msg = strlen( $response ) > 200 ? substr( $response, 0, 200 ) . '…' : $response;
	echo json_encode( array( 'error' => 'Vectorizer.AI вернул код ' . $code . '. ' . $msg ) );
	exit;
}

$svg = trim( $response );
if ( strpos( $svg, '<svg' ) === false && strpos( $svg, '<?xml' ) === false ) {
	echo json_encode( array( 'error' => 'Ответ не похож на SVG: ' . substr( $svg, 0, 150 ) . '…' ) );
	exit;
}

$svg = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $svg );
$svg = preg_replace( '/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg );
$svg = preg_replace( '/\s+on\w+\s*=\s*[^\s>]+/i', '', $svg );
$svg = trim( $svg );

echo json_encode( array( 'svg' => $svg ) );
