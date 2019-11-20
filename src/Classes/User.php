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

namespace Influx\Classes;

use Psr\Log\LoggerInterface;

/**
 * Class User
 */
class User
{
    /*
     * `id` int(11) NOT NULL AUTO_INCREMENT,
                             `login` varchar(225) NOT NULL,
                             `hash` varchar(225) NOT NULL,
                             `email` varchar(225) NOT NULL,
                             `otpSecret` varchar(225) DEFAULT NULL,
     */

    private $id;
    private $login = '';
    private $hash = '';
    private $email = '';
    private $otpSecret = '';
    private $token = '';

    /** @var LoggerInterface|null */
    private $logger;

    private $prefix;

    private const TABLE_BASE_NAME = 'user';

    /**
     * @var \mysqli
     */
    private $db;

    public function __construct($db, $prefix = null, $logger = null)
    {
        $this->db = $db;
        $this->prefix = $prefix ?? '';
        $this->logger = $logger;

    }

    /**
     * @param null $param
     * @return bool
     */
    public function userExistBy($param = NULL): ?bool
    {
        switch ($param) {
            case 'login':
                $cond = 'login';
                $needle = $this->getLogin();
                break;
            case 'email':
                $cond = 'email';
                $needle = $this->getEmail();
                break;
            default:
                $cond = 'login';
                $needle = $this->getLogin();
        }

        $count = 0;

        if (false !== ($stmt = $this->db->prepare('select count(' . $cond . ') as cn from ' . $this->getTableName() . ' where ' . $cond . ' = ?'))) {
            $stmt->bind_param('s', $needle);

            $stmt->execute();

            /* instead of bind_result: */
            $result = $stmt->get_result();

            while ($row = $result->fetch_array()) {
                $count = $row['cn'];
            }

            if ($count === 1) {
                return true;
            }

            return false;
        }

    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function createTokenForUser()
    {
        $token = bin2hex(random_bytes(50));

        $this->db->query(sprintf('UPDATE %s SET token = "%s" where email = "%s"', $this->getTableName(), $token, $this->getEmail()));

        return true;
    }

    /**
     * @param $password
     * @return bool
     */
    public function checkPassword($password)
    {
        $query = 'select id,login,password as hash from ' . $this->getTableName() . ' where login = ?';
        if (false !== ($stmt = $this->db->prepare($query))) {
            $stmt->bind_param('s', $this->getLogin());

            $stmt->execute();

            /* instead of bind_result: */
            $result = $stmt->get_result();

            while ($row = $result->fetch_array()) {
                $this->setHash($row['hash']);
                $this->setLogin($row['login']);
                $this->setId($row['id']);
            }
        }

        if (password_verify($password, $this->hash)) {
            $this->logger->info('Bon password');
            return true;
        } else {
            $this->logger->info('Mauvais password');
            return false;
        }
    }

    /**
     * @return bool|string
     */
    protected function createSynchronisationCode()
    {
        return substr(sha1(rand(0, 30) . time() . rand(0, 30)), 0, 10);
    }

    /**
     * @param $password
     * @return bool|false|string
     */
    function resetPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @param $password
     */
    public function createHash($password)
    {
        $this->setHash(password_hash($password, PASSWORD_DEFAULT));

        $q = sprintf('UPDATE %s set hash = %s where token = %s', $this->getTableName(), $this->getHash(), $this->getToken());

        $this->logger->info($q);

        $return = $this->db->query($q);
    }

    /**
     *
     */
    public function createCookie()
    {
        $value = $this->getLogin() . ',' . hash('sha256', $this->getLogin() . $this->getSecret() . $this->getId());
        setcookie('login', $value, time() + 365 * 24 * 3600, null, null, false, true);
    }

    /**
     * @param $cookieValue
     * @return bool
     */
    public function validateCookie($cookieValue)
    {
        return $cookieValue === $this->getLogin() . ',' . hash('sha256', $this->getLogin() . $this->getSecret() . $this->getId());
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getLogin()
    {
        return $this->login;
    }

    /**
     * @param string $login
     */
    public function setLogin($login)
    {
        $this->login = $login;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param string $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @return string
     */
    public function getOtpSecret()
    {
        return $this->otpSecret;
    }

    /**
     * @param string $otpSecret
     */
    public function setOtpSecret($otpSecret)
    {
        $this->otpSecret = $otpSecret;
    }

    /**
     * @return string
     */
    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * @param string $salt
     */
    public function setSalt($salt)
    {
        $this->salt = $salt;
    }

    /**
     * @return mixed
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param mixed $db
     */
    public function setDb($db)
    {
        $this->db = $db;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @param string $hash
     */
    public function setHash($hash)
    {
        $this->hash = $hash;
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @param string $token
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    private function getTableName()
    {
        return $this->prefix . self::TABLE_BASE_NAME;
    }


}