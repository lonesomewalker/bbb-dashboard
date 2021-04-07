<?php

/* GNU AFFERO GENERAL PUBLIC LICENSE Version 3 */

session_start();
$_SESSION['lang'] = !empty($_SESSION['lang']) ? $_SESSION['lang'] : 'EN';
$_SESSION['languages'] = ['DA', 'EN', 'ES', 'FR', 'DE', 'IT', 'NL', 'PL', 'PT', 'SV'];

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use DI\Container;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use BigBlueButton\Parameters\DeleteRecordingsParameters;

require __DIR__ . '/vendor/autoload.php';

AppFactory::setSlimHttpDecoratorsAutomaticDetection(false);
ServerRequestCreatorFactory::setSlimHttpDecoratorsAutomaticDetection(false);

$container = new Container();

$container->set('settings', function(){
	return (object)require_once __DIR__ . '/config.php';
});

$container->set('Translation', function(){
	class TranslatorExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('translate', [$this, 'translate']),
			];
		}

		public function translate($text)
		{
			$lang = $_SESSION['lang'];
			$translations = require __DIR__ . '/translations/' . $lang . '.php';
			if (!empty($translations[$text])) {
				return $translations[$text];
			}

			return $text;
		}
	}

	return new TranslatorExtension();
});

$settings = $container->get('settings');
$translations = $container->get('Translation');

$container->set('view', function() use ($settings, $translations) {
	$loader = new FilesystemLoader(__DIR__ . '/templates');
	$view = new Twig($loader, [
		'debug' => true, // This line should enable debug mode
		'cache' => __DIR__ . '/cache',
	]);

	// This line should allow the use of {{ dump() }}
	$view->addExtension(new DebugExtension());
	$view->getEnvironment()->addGlobal('session', $_SESSION);
	$view->getEnvironment()->addGlobal('settings', $settings);
	$view->addExtension($translations);
	return $view;
});

$container->set('DateToTimestamp', function() {
	return function ($dateString) {
		$dateExp = explode('/', $dateString);
		$dateTime = new DateTime();
		$dateTime->setDate($dateExp[2], $dateExp[0], $dateExp[1]);

		return $dateTime->format('U');
	};
});

$container->set('BBBStats', function() {
	return function ($bbbUrl, $bbbSecret) {
		putenv('BBB_SERVER_BASE_URL=' . $bbbUrl);
		putenv('BBB_SECRET=' . $bbbSecret);

		$BBB = new BigBlueButton\BigBlueButton();
		$responseB = $BBB->getMeetings();
		$meetings = [];

		$meetings['total_meetings'] = 0;
		$meetings['total_meeting_attendances'] = 0;
		if ($responseB->getReturnCode() == 'SUCCESS') {
			foreach ($responseB->getRawXml()->meetings->meeting as $meeting) {
				$meetings['session_room'][] = $meeting;
				$meetings['total_meeting_attendances'] += count($meeting->attendees->attendee);
				++$meetings['total_meetings'];
			}
		}

		$meetings = (object)$meetings;

		return $meetings;
	};
});

$container->set('BBBRecordings', function() {
	return function ($bbbUrl, $bbbSecret, $filter) {
		putenv('BBB_SERVER_BASE_URL=' . $bbbUrl);
		putenv('BBB_SECRET=' . $bbbSecret);

		$recordingParams = new BigBlueButton\Parameters\GetRecordingsParameters();
		$BBB = new BigBlueButton\BigBlueButton();
		$responseB = $BBB->getRecordings($recordingParams);

		$recordings = [];
		$recordings['total_recordings'] = 0;
        $recordings['data'] = [];
		if ($responseB->getReturnCode() == 'SUCCESS') {
			foreach ($responseB->getRawXml()->recordings->recording as $recording) {
			    $recordingTime = (int)($recording->startTime / 1000);
				if (!empty($filter)) {
					if (!empty($filter['filter_start_date']) && !empty($filter['filter_end_date'])) {
						if ($filter['filter_start_date'] <= $recordingTime && $recordingTime <= $filter['filter_end_date']) {
							$recording->name = htmlspecialchars_decode($recording->name);
							$recordings['data'][] = (object)$recording;
							++$recordings['total_recordings'];
						}
					}
				} else {
					$recording->name = htmlspecialchars_decode($recording->name);
					$recordings['data'][] = (object)$recording;
					++$recordings['total_recordings'];
				}
			}
		}

		usort($recordings['data'], function($a, $b) { return (int)$a->startTime < (int)$b->startTime ? 1 : -1; });

		return $recordings;
	};
});

$container->set('BBBDeleteRecording', function() {
	return function ($bbbUrl, $bbbSecret, $recordingId) {
		putenv('BBB_SERVER_BASE_URL=' . $bbbUrl);
		putenv('BBB_SECRET=' . $bbbSecret);

		$deleteRecordingsParams = new DeleteRecordingsParameters($recordingId);
		$BBB = new BigBlueButton\BigBlueButton();
		$responseB = $BBB->deleteRecordings($deleteRecordingsParams);

		return $responseB;
	};
});

AppFactory::setContainer($container);

// Instantiate App
$app = AppFactory::create();
// BasePath
if (!empty($container->get('settings')->path)) {
	$app->setBasePath($container->get('settings')->path);
}

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Add routes
$app->get('/', function (Request $request, Response $response) {
	$queryParams = $request->getQueryParams();
	$_SESSION['lang'] = (!empty($queryParams['lang']) && (in_array($queryParams['lang'], $_SESSION['languages']))) ? $queryParams['lang'] : $_SESSION['lang'];
	$settings = $this->get('settings');

	$servers = [];
	$totalActiveMeetings = 0;
	$totalActiveUsers = 0;
	foreach ($settings->servers as $serverName => $serverBBB) {
		$servers[$serverName] = $this->get('BBBStats')
			($serverBBB['bbb_api_url'], $serverBBB['bbb_api_password']);

		$totalActiveMeetings += $servers[$serverName]->total_meetings;
		$totalActiveUsers += $servers[$serverName]->total_meeting_attendances;
	}

	return $this->get('view')->render($response, 'index.twig',
		[
			'servers' => $servers,
			'total_active_meetings' => $totalActiveMeetings,
			'total_meeting_attendances' => $totalActiveUsers,
			'url' => $request->getUri()
		]);
});

$app->get('/recordings', function (Request $request, Response $response) {
    $settings = $this->get('settings');
	$queryParams = $request->getQueryParams();
	$_SESSION['lang'] = (!empty($queryParams['lang']) && (in_array($queryParams['lang'], $_SESSION['languages']))) ? $queryParams['lang'] : $_SESSION['lang'];
    $currentTab = isset($queryParams['current_tab'])? $queryParams['current_tab'] : '';
    $returnCode = isset($queryParams['return_code'])? $queryParams['return_code'] : '';
    $message = isset($queryParams['message'])? $queryParams['message'] : '';

    $filter = [
		'filter_start_date' => !empty($queryParams['filter_start_date']) ? $this->get('DateToTimestamp')($queryParams['filter_start_date']) : time() - 24 * 60 * 60,
	   'filter_end_date' => !empty($queryParams['filter_end_date']) ? $this->get('DateToTimestamp')($queryParams['filter_end_date']) : time()
    ];

    $servers = [];
    $serverRecordings = [];
    $totalRecordings = 0;
    foreach ($settings->servers as $serverName => $serverBBB) {
        $servers[$serverName] = $serverName;

        $serverRecordings[$serverName] = $this->get('BBBRecordings')
        ($serverBBB['bbb_api_url'], $serverBBB['bbb_api_password'], $filter);

        $totalRecordings += $serverRecordings[$serverName]['total_recordings'];
    }

    return $this->get('view')->render($response, 'recordings.twig',
        [
            'servers' => $servers,
            'recordings' => $serverRecordings,
            'filter' => $filter,
            'total_recordings' => $totalRecordings,
            'url' => $request->getUri(),
            'current_tab' => $currentTab,
            'return_code' => $returnCode,
            'message' => $message,
	         'permission' => $settings->permissions
        ]);
});

$app->get('/delete/recording', function (Request $request, Response $response) {
    $queryParams = $request->getQueryParams();
    $currentTab = $queryParams['server'];
    $recordingId = $queryParams['recordingId'];
	 $settings = $this->get('settings');
	 $server = $settings->servers[$queryParams['server']];
    try {
	    $responseB = $this->get('BBBDeleteRecording')
	    ($server['bbb_api_url'], $server['bbb_api_password'], $recordingId);

        if ($responseB->getReturnCode() == 'SUCCESS') {
            $returnCode = 200;
            $message = 'Recording successfully deleted!';
        } else {
            $returnCode = 500;
            $message = 'An error occurred!';
        }

    } catch (Exception $e) {
        $returnCode = 500;
        $message = $e->getMessage();
    }

    return $response->withHeader('Location', '/recordings?current_tab='.$currentTab.'&return_code='.$returnCode.'&message='.$message);
});

$app->get('/download/recording', function (Request $request, Response $response) {
	$queryParams = $request->getQueryParams();
	$currentTab = $queryParams['server'];
	$recordingId = $queryParams['recordingId'];
	$settings = $this->get('settings');
	$server = $settings->servers[$queryParams['server']];
	$urlInfo = parse_url($server['bbb_api_url']);
	$serverUrl = $urlInfo['scheme'] . '://' . $urlInfo['host'] . '/presentation/' . $recordingId . '/video.mp4';

	return $response->withHeader('Location', $serverUrl);
});

$app->run();
