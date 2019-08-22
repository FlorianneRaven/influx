<?php

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

error_reporting(E_ALL & ~E_NOTICE);

require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\LabelAlignment;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Response\QrCodeResponse;
use Sinergi\BrowserDetector\Language;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Influx\Flux;
use Influx\Items;
use Influx\User;
use Influx\Category;
use Influx\Opml;
use Influx\Configuration;
use Influx\Statistics;

if (defined('LOGS_DAYS_TO_KEEP')) {
    $handler = new RotatingFileHandler(__DIR__ . '/logs/influx.log', LOGS_DAYS_TO_KEEP);
} else {
    $handler = new RotatingFileHandler(__DIR__ . '/logs/influx.log', 7);
}

$stream = new StreamHandler(__DIR__ . '/logs/influx.log', Logger::DEBUG);

$logger = new Logger('influxLogger');
$logger->pushHandler($handler);
$logger->pushHandler($stream);

// Create Router instance
$router = new \Bramus\Router\Router();

session_start();

if (file_exists('conf/config.php')) {
    require_once('conf/config.php');

    /* ---------------------------------------------------------------- */
    // Database
    /* ---------------------------------------------------------------- */

    $_SESSION['install'] = false;

    $db = new mysqli(MYSQL_HOST, MYSQL_LOGIN, MYSQL_MDP, MYSQL_BDD);
    $db->set_charset('utf8mb4');
    $db->query('SET NAMES utf8mb4');

    $conf = new Configuration($db);
    $config = $conf->getAll();

    $templateName = 'influx';
    $templatePath = __DIR__ . '/templates/' . $templateName;

    $loader = new \Twig\Loader\FilesystemLoader($templatePath);
    $twig = new \Twig\Environment($loader, ['cache' => __DIR__ . '/cache', 'debug' => true,]);
    $twig->addExtension(new \Twig\Extension\DebugExtension());

    $fluxObject = new Flux($db, $logger);
    $itemsObject = new Items($db, $logger);
    $userObject = new User($db, $logger);
    $categoryObject = new Category($db, $logger);
    $opmlObject = new Opml($db, $logger);

    $synchronisationCode = $config['synchronisationCode'];

    mb_internal_encoding('UTF-8');
    $start = microtime(true);

    /* ---------------------------------------------------------------- */
    // Timezone
    /* ---------------------------------------------------------------- */

    $timezone_default = 'Europe/Paris';
    date_default_timezone_set($timezone_default);

    $scroll = false;
    $unreadEventsForCategory = 0;
    $highlighted = 0;

    $page = 1;

} else {
    if (!isset($_SESSION['install'])) {
        $_SESSION['install'] = true;
        header('location: /install');
        exit();
    }

}

function getClientIP()
{
    if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
        return $_SERVER["HTTP_X_FORWARDED_FOR"];
    } else if (array_key_exists('REMOTE_ADDR', $_SERVER)) {
        return $_SERVER["REMOTE_ADDR"];
    } else if (array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
        return $_SERVER["HTTP_CLIENT_IP"];
    }

    return '';
}

/* ---------------------------------------------------------------- */
// i18n
/* ---------------------------------------------------------------- */

$language = new Language();

if (isset($config['language'])) {
    if ($language->getLanguage() == $config['language'] && is_file('locales/' . $config['language'] . '.json')) {
        $_SESSION['language'] = $language->getLanguage();
        $l_trans = json_decode(file_get_contents('templates/' . $templateName . '/locales/' . $config['language'] . '.json'), true);
    } elseif ($language->getLanguage() != $config['language'] && is_file('locales/' . $config['language'] . '.json')) {
        $_SESSION['language'] = $language->getLanguage();
        $l_trans = json_decode(file_get_contents('templates/' . $templateName . '/locales/' . $config['language'] . '.json'), true);
    } elseif (!is_file('locales/' . $config['language'] . '.json')) {
        $_SESSION['language'] = 'en';
        $l_trans = json_decode(file_get_contents('templates/' . $templateName . '/locales/' . $_SESSION['language'] . '.json'), true);
    }
} else {
    $_SESSION['language'] = 'en';
    $l_trans = json_decode(file_get_contents('templates/' . $templateName . '/locales/' . $_SESSION['language'] . '.json'), true);
}

$trans = $l_trans;

/* ---------------------------------------------------------------- */
// Cookie
/* ---------------------------------------------------------------- */

$cookiedir = '';
if (dirname($_SERVER['SCRIPT_NAME']) != '/') {
    $cookiedir = dirname($_SERVER["SCRIPT_NAME"]) . '/';
}

/* ---------------------------------------------------------------- */
// Route: Before for logging
/* ---------------------------------------------------------------- */

$router->before('GET|POST|PUT|DELETE|PATCH|OPTIONS', '/.*', function () use ($logger) {

    $logger->info("before");
    $logger->info($_SERVER['REQUEST_URI']);
    $logger->info(getClientIP());
    $logger->info($_SESSION['install']);
    $logger->info($_SESSION['user']);

    if (!isset($_SESSION['install']) && !isset($_SESSION['user']) && $_SERVER['REQUEST_URI'] == '/password/recover') {
        header('Location: /password/recover');
        exit();
    } elseif (!isset($_SESSION['install']) && !isset($_SESSION['user']) && $_SERVER['REQUEST_URI'] !== '/login') {
        header('Location: /login');
        exit();
    } else if (isset($_SESSION['install']) && $_SESSION['install'] && $_SERVER['REQUEST_URI'] !== '/install') {
        header('Location: /install');
        exit();
    } else {
        $logger->info("on passe dans ce before");
        //header('Location: /');
        //exit();
    }
});

/* ---------------------------------------------------------------- */
// Route: / (GET)
/* ---------------------------------------------------------------- */
$router->get('/', function () use (
    $twig,
    $logger,
    $scroll,
    $config,
    $db,
    $trans,
    $itemsObject,
    $categoryObject
) {

    $action = 'all';
    $numberOfItem = $itemsObject->countAllUnreadItem();
    $page = (isset($_GET['page']) ? $_GET['page'] : 1);
    $startArticle = ($page - 1) * $config['articlePerPages'];

    $offset = ($page - 1) * 25; //$config['articlePerPages'];
    $row_count = 25; //$config['articlePerPages'];

    echo $twig->render('index.twig',
        [
            'action' => $action,
            'config' => $config,
            'events' => $itemsObject->loadAllUnreadItem($offset, $row_count),
            'categories' => $categoryObject->getFluxByCategories(),
            'numberOfItem' => $numberOfItem,
            'page' => $page,
            'startArticle' => $startArticle,
            'user' => $_SESSION['user'],
            'scroll' => $scroll,
            'trans' => $trans
        ]
    );

});

/* ---------------------------------------------------------------- */
// Route: /login
/* ---------------------------------------------------------------- */

$router->get('/login', function () use ($twig) {
    echo $twig->render('login.twig');
});

/* ---------------------------------------------------------------- */
// Route: /login (POST)
/* ---------------------------------------------------------------- */

$router->post('/login', function () use ($db, $config, $logger, $userObject) {

    $userObject->setLogin($_POST['login']);

    if ($userObject->checkPassword($_POST['password'])) {

        $_SESSION['user'] = $_POST['login'];
        $_SESSION['userId'] = $userObject->getId();
        $_SESSION['userEmail'] = $userObject->getEmail();
        if (isset($_POST['rememberMe'])) {
            setcookie('InfluxChocolateCookie', sha1($_POST['password'] . $_POST['login']), time() + 31536000);
        }
        header('location: /');

    } else {
        header('location: /login');
    }

});

/* ---------------------------------------------------------------- */
// Route: /password/recover (GET)
/* ---------------------------------------------------------------- */

$router->get('/password/recover', function () use ($db, $twig, $config, $logger, $trans) {

    echo $twig->render('recover.twig', []);

});

// Route: /password/new/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/password/new/{token}', function ($token) use ($db, $twig, $config, $logger, $trans, $userObject) {

    $userObject->setToken($token);
    $userInfos = $userObject->getUserInfosByToken();
    echo $twig->render('password.twig', ['token' => $token]);

});

$router->post('/password/new', function () use ($db, $twig, $config, $logger, $trans, $userObject) {

    $userObject->setToken($_POST['token']);
    $userInfos = $userObject->createHash($_POST['password']);
    header('location: /');

});


/* ---------------------------------------------------------------- */
// Route: /password/recover (POST)
/* ---------------------------------------------------------------- */

$router->post('/password/recover', function () use ($db, $config, $logger) {

    $token = bin2hex(random_bytes(50));

    if ($stmt = $db->prepare("select id,login,email from user where email = ?")) {
        $stmt->bind_param("s", $_POST['email']);
        /* execute query */
        $stmt->execute();

        /* instead of bind_result: */
        $result = $stmt->get_result();

        while ($row = $result->fetch_array()) {
            $login = $row['login'];
            $email = $row['email'];

        }
    }

    if (!empty($login)) {
        $db->query("UPDATE user SET token = '" . $token . "' where email = '" . $email . "'");
    }

    $logger->error("Message could not be sent to: " . $email);
    $logger->error("Message could not be sent to: " . $_POST['email']);

    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->SMTPDebug = 2;                                       // Enable verbose debug output
        $mail->isSMTP();                                            // Set mailer to use SMTP
        $mail->Host = SMTP_HOST;  // Specify main and backup SMTP servers
        $mail->SMTPAuth = SMTP_AUTH;                                   // Enable SMTP authentication
        $mail->Username = SMTP_LOGIN;                     // SMTP username
        $mail->Password = SMTP_PASSWORD;                               // SMTP password
        $mail->SMTPSecure = SMTP_SECURE;                                  // Enable TLS encryption, `ssl` also accepted
        $mail->Port = SMTP_PORT;                                    // TCP port to connect to

        //Recipients
        $mail->setFrom('rss@neurozone.fr', 'no-reply@neurozone.fr');
        $mail->addAddress($email, $login);

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = 'Reset your password on InFlux';
        $mail->Body = 'Hi there, click on this <a href="https://influx.neurozone.fr/password/new/' . $token . '">link</a> to reset your password on our site';
        $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

        $mail->send();
        echo 'Message has been sent';
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        $logger->error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }

});

/* ---------------------------------------------------------------- */
// Route: /logout (GET)
/* ---------------------------------------------------------------- */

$router->get('/logout', function () {

    setcookie('InfluxChocolateCookie', '', -1);
    $_SESSION = array();
    session_unset();
    session_destroy();
    header('location: /login');

});

// @TODO: à mettre en place

/* ---------------------------------------------------------------- */
// Route: /update (GET)
/* ---------------------------------------------------------------- */

$router->get('/update', function () {

    setcookie('InfluxChocolateCookie', '', -1);
    $_SESSION = array();
    session_unset();
    session_destroy();
    header('location: /');

});

/* ---------------------------------------------------------------- */
// Route: /favorites (GET)
/* ---------------------------------------------------------------- */

$router->get('/favorites', function () use (
    $twig, $logger,
    $scroll,
    $config,
    $db,
    $categoryObject,
    $itemsObject,
    $fluxObject
) {

    $numberOfItem = $itemsObject->getNumberOfFavorites();
    $flux = $fluxObject->getFluxById();

    $page = (isset($_GET['page']) ? $_GET['page'] : 1);
    $startArticle = ($page - 1) * $config['articlePerPages'];

    $offset = ($page - 1) * $config['articlePerPages'];
    $row_count = $config['articlePerPages'];

    echo $twig->render('index.twig',
        [

            'events' => $itemsObject->getAllFavorites($offset, $row_count),
            'category' => $categoryObject->getFluxByCategories(),
            'numberOfItem' => $numberOfItem,
            'page' => $page,
            'startArticle' => $startArticle,
            'user' => $_SESSION['user'],
            'scroll' => $scroll

        ]
    );

});

/* ---------------------------------------------------------------- */
// Route: /article (GET)
/* ---------------------------------------------------------------- */

// @todo

$router->mount('/article', function () use ($router, $twig, $db, $logger, $trans, $config, $itemsObject) {

    /* ---------------------------------------------------------------- */
    // Route: /article (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/', function () use ($twig, $db, $logger, $trans, $config) {

        header('location: /settings/manage');

    });

    /* ---------------------------------------------------------------- */
    // Route: /article/favorite (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/favorites', function () use ($twig, $db, $logger, $trans, $config) {

        header('location: /settings/manage');

    });

    /* ---------------------------------------------------------------- */
    // Route: /article/unread (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/unread', function () use ($twig, $db, $logger, $trans, $config) {

        header('location: /settings/manage');

    });

    /* ---------------------------------------------------------------- */
    // Route: /article/flux/ (POST)
    /* ---------------------------------------------------------------- */

    $router->post('/flux', function () use ($twig, $db, $logger, $config, $itemsObject) {

        $scroll = $_POST['scroll'];
        $hightlighted = $_POST['hightlighted'];
        $action = $_POST['action'];
        $category = $_POST['category'];
        $flux = (int)$_POST['flux'];

        $nblus = isset($_POST['nblus']) ? $_POST['nblus'] : 0;

        $articleConf['startArticle'] = ($scroll * 50) - $nblus;

        $logger->info($articleConf['startArticle']);
        $logger->info($config['articlePerPages']);

        $offset = $articleConf['startArticle'];
        $rowcount = $articleConf['startArticle'] + $config['articlePerPages'];

        if ($articleConf['startArticle'] < 0) {
            $articleConf['startArticle'] = 0;
        }
        
        $itemsObject->setFlux($flux);
        $items = $itemsObject->loadUnreadItemPerFlux($offset, $rowcount);

        echo $twig->render('article.twig',
            [
                'events' => $items,
                'scroll' => $scroll,
            ]
        );

    });

    /* ---------------------------------------------------------------- */
    // Route: /article/category/{id} (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/category/{id}', function () use ($twig, $db, $logger, $trans, $config) {

        if (!$_SESSION['user']) {
            header('location: /login');
        }

        header('location: /settings/manage');

    });


});

/* ---------------------------------------------------------------- */
// Route: /settings (GET)
/* ---------------------------------------------------------------- */

$router->mount('/settings', function () use ($router, $twig, $trans, $logger, $config, $db, $cookiedir, $categoryObject, $fluxObject, $opmlObject) {

    $router->get('/', function () use ($twig, $cookiedir) {

        header('location: /settings/manage');

    });

    $router->get('/settings/user', function () use ($twig, $cookiedir) {

        header('location: /settings/manage');

    });


    /* ---------------------------------------------------------------- */
    // Route: /settings/synchronize/all (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/synchronize', function ($option) use ($twig, $trans, $logger, $config, $cookiedir) {


    });

    /* ---------------------------------------------------------------- */
    // Route: /statistics (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/statistics', function () use ($twig, $trans, $logger, $config, $db) {

        echo '';

    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/flux/add (POST)
    /* ---------------------------------------------------------------- */

    $router->post('/flux/add', function () use ($twig, $trans, $logger, $config, $fluxObject) {

        $cat = isset($_POST['newUrlCategory']) ? $_POST['newUrlCategory'] : 1;
        $sp = new SimplePie();

        $fluxObject->setUrl($_POST['newUrl']);

        if ($fluxObject->notRegistered()) {

            //$fluxObject->getInfos();
            $fluxObject->setcategory((isset($_POST['newUrlCategory']) ? $_POST['newUrlCategory'] : 1));
            $fluxObject->add($sp);

        } else {

            $logger->info($trans['FEED_ALREADY_STORED']);
        }
        header('location: /settings/manage');
    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/flux/remove/{id} (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/flux/remove/{id}', function ($id) use ($twig, $trans, $logger, $config, $fluxObject) {


        $fluxObject->setId($id);
        $logger->info($fluxObject->getId($id));
        $fluxObject->remove();

        header('location: /settings/manage');
    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/flux/rename (POST)
    // Action: Rename flux
    /* ---------------------------------------------------------------- */

    $router->post('/flux/rename', function () use ($logger, $fluxObject) {

        // data:{id:flux,name:fluxNameValue,url:fluxUrlValue}

        $fluxObject->setId($_POST['id']);
        $fluxObject->setName($_POST['name']);
        $fluxObject->setUrl($_POST['url']);

        return $fluxObject->rename();

    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/flux/category/{id} (GET)
    // Action: Rename flux
    /* ---------------------------------------------------------------- */

    $router->get('/flux/category/{id}', function ($id) use ($twig, $trans, $logger, $config, $db, $fluxObject) {

        $fluxObject->setCategory($_GET['id']);
        $fluxObject->setName($_GET['name']);
        $fluxObject->setUrl($_GET['url']);
        $fluxObject->changeCategory();

        header('location: /settings');
    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/category/add (POST)
    /* ---------------------------------------------------------------- */

    $router->post('/category/add', function () use ($twig, $db, $logger, $trans, $config, $categoryObject) {

        $name = $_POST['categoryName'];
        $categoryObject->setName($name);
        if (isset($_POST['categoryName']) && !$categoryObject->exist()) {

            $categoryObject->add();
        }
        header('location: /settings/manage');
    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/category/remove/{id} (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/category/remove/{id}', function ($id) use ($twig, $db, $logger, $trans, $config) {

        if (isset($id) && is_numeric($id) && $id > 0) {
            //$eventManager->customQuery('DELETE FROM `' . MYSQL_PREFIX . 'items` WHERE `' . MYSQL_PREFIX . 'event`.`flux` in (SELECT `' . MYSQL_PREFIX . 'flux`.`id` FROM `' . MYSQL_PREFIX . 'flux` WHERE `' . MYSQL_PREFIX . 'flux`.`category` =\'' . intval($_['id']) . '\') ;');
            //$fluxManager->delete(array('category' => $id));
            //$categoryManager->delete(array('id' => $id));
        }
        header('location: /settings/manage');
    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/category/rename (POST)
    /* ---------------------------------------------------------------- */

    $router->post('/category/rename', function () use ($twig, $db, $logger, $trans, $config, $categoryObject) {

        $id = $_POST['id'];
        $name = $_POST['name'];
        $categoryObject->setId($id);
        $categoryObject->setName($name);

        $logger->info(" avant le if rename");

        if (isset($_POST['id']) && $categoryObject->exist()) {

            $logger->info(" on rentre dans le if rename");
            $categoryObject->rename();
        }
        header('location: /settings/manage');

    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/flux/export (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/flux/export', function () use ($twig, $db, $logger, $trans, $config, $opmlObject) {

        header('Content-Disposition: attachment;filename=export.opml');
        header('Content-Type: text/xml');

        echo $opmlObject->export();

    });

    $router->get('/flux/import', function () use ($twig, $db, $logger, $trans, $config) {


        /*
         * // On ne devrait pas mettre de style ici.
        echo "<html>
            <style>
                a {
                    color:#F16529;
                }

                html,body{
                        font-family:Verdana;
                        font-size: 11px;
                }
                .error{
                        background-color:#C94141;
                        color:#ffffff;
                        padding:5px;
                        border-radius:5px;
                        margin:10px 0px 10px 0px;
                        box-shadow: 0 0 3px 0 #810000;
                    }
                .error a{
                        color:#ffffff;
                }
                </style>
            </style><body>
\n";
        if ($myUser == false) exit(_t('YOU_MUST_BE_CONNECTED_ACTION'));
        if (!isset($_POST['importButton'])) break;
        $opml = new Opml();
        echo "<h3>" . _t('IMPORT') . "</h3><p>" . _t('PENDING') . "</p>\n";
        try {
            $errorOutput = $opml->import($_FILES['newImport']['tmp_name']);
        } catch (Exception $e) {
            $errorOutput = array($e->getMessage());
        }
        if (empty($errorOutput)) {
            echo "<p>" . _t('IMPORT_NO_PROBLEM') . "</p>\n";
        } else {
            echo "<div class='error'>" . _t('IMPORT_ERROR') . "\n";
            foreach ($errorOutput as $line) {
                echo "<p>$line</p>\n";
            }
            echo "</div>";
        }
        if (!empty($opml->alreadyKnowns)) {
            echo "<h3>" . _t('IMPORT_FEED_ALREADY_KNOWN') . " : </h3>\n<ul>\n";
            foreach ($opml->alreadyKnowns as $alreadyKnown) {
                foreach ($alreadyKnown as &$elt) $elt = htmlspecialchars($elt);
                $text = Functions::truncate($alreadyKnown->fluxName, 60);
                echo "<li><a target='_parent' href='{$alreadyKnown->xmlUrl}'>"
                    . "{$text}</a></li>\n";
            }
            echo "</ul>\n";
        }
        $syncLink = "action.php?action=synchronize&format=html";
        echo "<p>";
        echo "<a href='$syncLink' style='text-decoration:none;font-size:3em'>"
            . "↺</a>";
        echo "<a href='$syncLink'>" . _t('CLIC_HERE_SYNC_IMPORT') . "</a>";
        echo "<p></body></html>\n";
        break;
         *
         */

        echo $twig->render('settings.twig',
            [
                'action' => 'category',
                'section' => 'fluxs/import',
                'trans' => $trans,
                'otpEnabled' => false,
                'currentTheme' => $config['theme'],
                'config' => $config
            ]
        );

    });

    /* ---------------------------------------------------------------- */
    // Route: /settings/{option} (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/{option}', function ($option) use ($twig, $trans, $logger, $config, $cookiedir, $db, $categoryObject) {

        //$serviceUrl', rtrim($_SERVER['HTTP_HOST'] . $cookiedir, '/'));

        // gestion des thèmes
        $themesDir = 'templates/';
        $dirs = scandir($themesDir);
        foreach ($dirs as $dir) {
            if (is_dir($themesDir . $dir) && !in_array($dir, array(".", ".."))) {
                $themeList[] = $dir;
            }
        }
        sort($themeList);

        // @todo
        $resultsFlux = $db->query('SELECT id FROM flux f ORDER BY name ');
        while ($rows = $resultsFlux->fetch_array()) {
            $flux['id'] = $rows['id'];
        }

        $logger->info('Section: ' . $option);

        echo $twig->render('settings.twig',
            [
                'action' => 'category',
                'section' => $option,
                'trans' => $trans,
                'themeList' => $themeList,
                'otpEnabled' => false,
                'currentTheme' => $config['theme'],
                'categories' => $categoryObject->getFluxByCategories(),
                'flux' => $flux,
                'config' => $config,
                'user' => $_SESSION['user']
            ]
        );

    });

});

// @todo

/* ---------------------------------------------------------------- */
// Route: /action/read/all (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/read/all', function () use ($twig, $db, $logger, $trans, $config, $fluxObject) {

    $fluxObject->markAllRead();

    header('location: /');

});

/* ---------------------------------------------------------------- */
// Route: /action/read/flux/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/read/flux/{id}', function ($id) use ($twig, $db, $logger, $trans, $config, $fluxObject) {

    $fluxObject->setId($id);
    $fluxObject->markAllRead();
    header('location: /');

});

/* ---------------------------------------------------------------- */
// Route: /action/unread/flux/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/unread/flux/{id}', function ($id) use ($twig, $db, $logger, $trans, $config) {

    $result = $db->query("update items set unread = 1 where guid = '" . $id . "'");

});

/* ---------------------------------------------------------------- */
// Route: /action/read/item/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/read/item/{id}', function ($id) use ($twig, $db, $logger, $trans, $config, $itemsObject) {

    $itemsObject->setGuid($id);
    $itemsObject->markItemAsReadByGuid();
    header('location: /');

});

/* ---------------------------------------------------------------- */
// Route: /action/unread/item/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/unread/item/{id}', function ($id) use ($twig, $db, $logger, $trans, $config) {

    $result = $db->query("update items set unread = 1 where guid = '" . $id . "'");

});

// @todo

/* ---------------------------------------------------------------- */
// Route: /search (GET)
/* ---------------------------------------------------------------- */

$router->get('/search', function () use ($twig, $db, $logger, $trans, $config) {

    $search = $this->escape_string($_GET['plugin_search']);
    $requete = "SELECT title,guid,content,description,link,pubdate,unread, favorite FROM items 
            WHERE title like '%" . htmlentities($search) . '%\'  OR content like \'%' . htmlentities($search) . '%\' ORDER BY pubdate desc';

});

// @todo

/* ---------------------------------------------------------------- */
// Route: /action/read/category/{id} (GET)
/* ---------------------------------------------------------------- */
/*
$router->get('/action/read/category/{id}', function () use ($twig, $db,$logger,$trans,$config) {

    if (!$_SESSION['user']) {
        header('location: /login');
    }

    $whereClause = array();
    $whereClause['unread'] = '1';
    if (isset($_['flux'])) $whereClause['flux'] = $_['flux'];
    if (isset($_['last-event-id'])) $whereClause['id'] = '<= ' . $_['last-event-id'];
    $eventManager->change(array('unread' => '0'), $whereClause);
    if (!Functions::isAjaxCall()) {
        header('location: ./index.php');
    }

});
*/
// @todo

/* ---------------------------------------------------------------- */
// Route: /action/updateConfiguration (GET)
/* ---------------------------------------------------------------- */

$router->get('/action/updateConfiguration', function () use ($twig, $db, $logger, $trans, $config) {

    if (!$_SESSION['user']) {
        header('location: /login');
    }

    /*
    $whereClause = array();
    $whereClause['unread'] = '1';
    if (isset($_['flux'])) $whereClause['flux'] = $_['flux'];
    if (isset($_['last-event-id'])) $whereClause['id'] = '<= ' . $_['last-event-id'];
    $eventManager->change(array('unread' => '0'), $whereClause);
    if (!Functions::isAjaxCall()) {
        header('location: ./index.php');
    }
    */

});

// @todo

/* ---------------------------------------------------------------- */
// Route: /qrcode
// @TODO
/* ---------------------------------------------------------------- */

$router->mount('/qrcode', function () use ($router, $twig, $db, $logger, $trans, $config) {

    $router->get('/qr', function () {

        if (!$_SESSION['user']) {
            header('location: /login');
        }

        /*
        Functions::chargeVarRequest('label', 'user', 'key', 'issuer', 'algorithm', 'digits', 'period');
        if (empty($key)) {
            $key = "**********";
        }
        $qrCode = "otpauth://totp/{$label}:{$user}?secret={$key}";
        foreach (array('issuer', 'algorithm', 'digits', 'period') as $champ)
            if (!empty(${$champ}))
                $qrCode .= "&{$champ}={${$champ}}";


        Functions::chargeVarRequest('_qrSize', '_qrMargin');
        if (empty($_qrSize)) $_qrSize = 3;
        if (empty($_qrMargin)) $_qrMargin = 4;

        QRcode::png($qrCode, false, 'QR_LEVEL_H', $_qrSize, $_qrMargin);
    });

    $router->get('/text', function () use ($twig, $trans, $logger, $config) {

        $qrCode = substr($_SERVER['QUERY_STRING'], 1 + strlen($methode));
        */

    });

});

/* ---------------------------------------------------------------- */
// Route: /flux/{id} (GET)
/* ---------------------------------------------------------------- */

$router->get('/flux/{id}', function ($id) use (
    $twig,
    $logger,
    $trans,
    $scroll,
    $config,
    $db,
    $itemsObject,
    $fluxObject,
    $categoryObject
) {

    $fluxObject->setId($id);
    $flux = $fluxObject->getFluxById();
    $itemsObject->setFlux($id);
    $numberOfItem = $itemsObject->countUnreadItemPerFlux();

    $page = (isset($_GET['page']) ? $_GET['page'] : 1);
    $startArticle = ($page - 1) * $config['articlePerPages'];

    $offset = ($page - 1) * $config['articlePerPages'];
    $row_count = $config['articlePerPages'];

    echo $twig->render('index.twig',
        [
            'action' => 'items',
            'events' => $itemsObject->loadUnreadItemPerFlux($offset, $row_count),
            'flux' => $flux,
            'fluxId' => $id,
            'categories' => $categoryObject->getFluxByCategories(),
            'numberOfItem' => $numberOfItem,
            'page' => $page,
            'startArticle' => $startArticle,
            'user' => $_SESSION['user'],
            'scroll' => $scroll,
            'trans' => $trans,
            'config' => $config

        ]
    );

});

/* ---------------------------------------------------------------- */
// Route: /install
/* ---------------------------------------------------------------- */

$router->mount('/install', function () use ($router, $trans, $twig, $cookieDir, $logger) {

    /* ---------------------------------------------------------------- */
    // Route: /install (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/', function () use ($twig, $cookieDir, $trans) {

        $_SESSION['install'] = true;

        $installObject = new \Influx\Install();

        $templatesList = glob("templates/*");
        foreach ($templatesList as $tpl) {
            $tpl_array = explode(".", basename($tpl));
            $listTemplates[] = $tpl_array[0];
        }

        $fileList = glob("templates/influx/locales/*.json");

        foreach ($fileList as $file) {
            $locale = explode(".", basename($file));
            $list_lang[] = $locale[0];
        }

        //  echo $install->getDefaultRoot();

        $root = $installObject->getRoot();

        echo $twig->render('install.twig',
            [
                'action' => 'general',
                'list_lang' => $list_lang,
                'list_templates' => $listTemplates,
                'root' => $root,
                'trans' => $trans,
            ]);

    });

    /* ---------------------------------------------------------------- */
    // Route: /install/ (POST)
    /* ---------------------------------------------------------------- */

    $router->post('/', function () use ($twig, $cookieDir, $trans) {


        if ($_POST['action'] == 'database') {
            $_SESSION['language'] = $_POST['install_changeLng'];
            $_SESSION['template'] = $_POST['template'];
            $_SESSION['root'] = $_POST['root'];
        }

        if ($_POST['action'] == 'check') {
            $_SESSION['language'] = $_POST['install_changeLng'];
            $_SESSION['template'] = $_POST['template'];
            $_SESSION['root'] = $_POST['root'];
        }

        if ($_POST['action'] == 'admin') {
            $_SESSION['login'] = $_POST['login'];
            $_SESSION['password'] = $_POST['password'];
        }

        echo $twig->render('install.twig',
            [
                'action' => $_POST['action'],
                'trans' => $trans
            ]);

    });

    /* ---------------------------------------------------------------- */
    // Route: /install/database (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/database', function () use ($twig, $cookieDir, $trans) {

        $_SESSION['install'] = true;

        $filelist = glob("locales/*.json");

        foreach ($filelist as $file) {
            $locale = explode(".", basename($file));
            $list_lang[] = $locale[0];
        }

        $templateslist = glob("templates/*");
        foreach ($templateslist as $tpl) {
            $tpl_array = explode(".", basename($tpl));
            $listTemplates[] = $tpl_array[0];
        }

        echo $twig->render('install.twig',
            [
                'list_lang' => $list_lang,
                'list_templates' => $listTemplates,
                'trans' => $trans
            ]);

    });

    /* ---------------------------------------------------------------- */
    // Route: /install/user (GET)
    /* ---------------------------------------------------------------- */

    $router->get('/user', function () use ($twig, $cookieDir, $trans) {

        $_SESSION['install'] = true;

        $filelist = glob("locales/*.json");

        foreach ($filelist as $file) {
            $locale = explode(".", basename($file));
            $list_lang[] = $locale[0];
        }

        $templateslist = glob("templates/*");
        foreach ($templateslist as $tpl) {
            $tpl_array = explode(".", basename($tpl));
            $listTemplates[] = $tpl_array[0];
        }

        echo $twig->render('install.twig',
            [
                'list_lang' => $list_lang,
                'list_templates' => $listTemplates,
                'trans' => $trans
            ]);

    });

    /* ---------------------------------------------------------------- */
    // Route: /install (POST)
    // @todo
    /* ---------------------------------------------------------------- */

    $router->post('/', function () use ($twig, $cookieDir) {

        $install = new Install();
        /* Prend le choix de langue de l'utilisateur, soit :
         * - lorsqu'il vient de changer la langue du sélecteur ($lang)
         * - lorsqu'il vient de lancer l'installeur ($install_changeLngLeed)
         */
        $lang = '';
        if (isset($_GET['lang'])) $lang = $_GET['lang'];
        elseif (isset($_POST['install_changeLngLeed'])) $lang = $_POST['install_changeLngLeed'];
        $installDirectory = dirname(__FILE__) . '/install';
        // N'affiche que les langues du navigateur
        // @TODO: il faut afficher toutes les langues disponibles
        //        avec le choix par défaut de la langue préférée
        $languageList = Functions::getBrowserLanguages();
        if (!empty($lang)) {
            // L'utilisateur a choisi une langue, qu'on incorpore dans la liste
            array_unshift($languageList, $lang);
            $liste = array_unique($languageList);
        }
        unset($i18n); //@TODO: gérer un singleton et le choix de langue / liste de langue
        $currentLanguage = i18n_init($languageList, $installDirectory);
        $languageList = array_unique($i18n->languages);
        if (file_exists('constant.php')) {
            die('ALREADY_INSTALLED');
        }
        define('DEFAULT_TEMPLATE', 'influx');
        $templates = scandir('templates');
        if (!in_array(DEFAULT_TEMPLATE, $templates)) die('Missing default template : ' . DEFAULT_TEMPLATE);
        $templates = array_diff($templates, array(DEFAULT_TEMPLATE, '.', '..')); // Répertoires non voulus sous Linux
        sort($templates);
        $templates = array_merge(array(DEFAULT_TEMPLATE), $templates); // le thème par défaut en premier
// Cookie de la session
        $cookiedir = '';
        if (dirname($_SERVER['SCRIPT_NAME']) != '/') $cookiedir = dirname($_SERVER["SCRIPT_NAME"]) . '/';
        session_set_cookie_params(0, $cookiedir);
        session_start();
// Protection des variables
        $_ = array_merge($_GET, $_POST);
        $installActionName = 'installButton';
        $install->launch($_, $installActionName);

        $constant = "<?php
//Host de Mysql, le plus souvent localhost ou 127.0.0.1
define('MYSQL_HOST','{$this->options['db']['mysqlHost']}');
//Identifiant MySQL
define('MYSQL_LOGIN','{$this->options['db']['mysqlLogin']}');
//mot de passe MySQL
define('MYSQL_MDP','{$this->options['db']['mysqlMdp']}');
//Nom de la base MySQL ou se trouvera leed
define('MYSQL_BDD','{$this->options['db']['mysqlBase']}');
//Prefix des noms des tables leed pour les bases de données uniques
define('MYSQL_PREFIX','{$this->options['db']['mysqlPrefix']}');
?>";

        file_put_contents(self::CONSTANT_FILE, $constant);
        if (!is_readable(self::CONSTANT_FILE)) {
            die('"' . self::CONSTANT_FILE . '" not found!');
        }

        header('location: /login');

    });

});

$router->run();