<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Authentication;

use Espo\Core\Exceptions\Error;

use Espo\Entities\{
    User,
    AuthToken,
};

use Espo\Core\{
    ORM\EntityManager,
    Api\Request,
    Utils\Config,
    Utils\PasswordHash,
    Utils\Language,
};

use Espo\Core\Container;

class LDAP extends Espo
{
    private $utils;

    private $ldapClient;

    protected $config;
    protected $entityManager;
    protected $passwordHash;
    protected $container;
    protected $language;

    public function __construct(
        Config $config, EntityManager $entityManager, PasswordHash $passwordHash, Language $language, Container $container
    ) {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->passwordHash = $passwordHash;
        $this->language = $language;
        $this->container = $container;

        $this->utils = new LDAP\Utils($config);
    }

    /**
     * User field name  => option name (LDAP attribute)
     *
     * @var array
     */
    protected $ldapFieldMap = [
        'userName' => 'userNameAttribute',
        'firstName' => 'userFirstNameAttribute',
        'lastName' => 'userLastNameAttribute',
        'title' => 'userTitleAttribute',
        'emailAddress' => 'userEmailAddressAttribute',
        'phoneNumber' => 'userPhoneNumberAttribute',
    ];

    /**
     * User field name => option name
     *
     * @var array
     */
    protected $userFieldMap = [
        'teamsIds' => 'userTeamsIds',
        'defaultTeamId' => 'userDefaultTeamId',
    ];

    /**
     * User field name => option name
     *
     * @var array
     */
    protected $portalUserFieldMap = [
        'portalsIds' => 'portalUserPortalsIds',
        'portalRolesIds' => 'portalUserRolesIds',
    ];

    public function login(
        ?string $username,
        ?string $password,
        ?AuthToken $authToken = null,
        ?Request $request = null,
        array $params = [],
        array &$resultData = []
    ) : ?User {
        $isPortal = !empty($params['isPortal']);

        if ($authToken) {
            return $this->loginByToken($username, $authToken);
        }

        if (!$password || $username == '**logout') return;

        if ($isPortal) {
            $useLdapAuthForPortalUser = $this->utils->getOption('portalUserLdapAuth');
            if (!$useLdapAuthForPortalUser) {
                return parent::login($username, $password, $authToken, $request, $params, $resultData);
            }
        }

        $ldapClient = $this->getLdapClient();

        /* Login LDAP system user (ldapUsername, ldapPassword) */
        try {
            $ldapClient->bind();
        } catch (\Exception $e) {
            $options = $this->utils->getLdapClientOptions();
            $GLOBALS['log']->error('LDAP: Could not connect to LDAP server ['.$options['host'].'], details: ' . $e->getMessage());

            $adminUser = $this->adminLogin($username, $password);
            if (!isset($adminUser)) {
                return null;
            }

            $GLOBALS['log']->info('LDAP: Administrator ['.$username.'] was logged in by Espo method.');
        }

        if (!isset($adminUser)) {
            try {
                $userDn = $this->findLdapUserDnByUsername($username);
            } catch (\Exception $e) {
                $GLOBALS['log']->error('Error while finding DN for ['.$username.'], details: ' . $e->getMessage() . '.');
            }

            if (!isset($userDn)) {
                $GLOBALS['log']->error('LDAP: Authentication failed for user ['.$username.'], details: user is not found.');

                $adminUser = $this->adminLogin($username, $password);
                if (!isset($adminUser)) {
                    return null;
                }

                $GLOBALS['log']->info('LDAP: Administrator ['.$username.'] was logged in by Espo method.');
            }

            $GLOBALS['log']->debug('User ['.$username.'] is found with this DN ['.$userDn.'].');

            try {
                $ldapClient->bind($userDn, $password);
            } catch (\Exception $e) {
                $GLOBALS['log']->error('LDAP: Authentication failed for user ['.$username.'], details: ' . $e->getMessage());
                return null;
            }
        }

        $user = $this->entityManager->getRepository('User')->findOne([
            'whereClause' => [
                'userName' => $username,
                'type!=' => ['api', 'system']
            ]
        ]);

        if (!isset($user)) {
            if (!$this->utils->getOption('createEspoUser')) {
                $this->useSystemUser();
                throw new Error($this->language->translate('ldapUserInEspoNotFound', 'messages', 'User'));
            }

            $userData = $ldapClient->getEntry($userDn);
            $user = $this->createUser($userData, $isPortal);
        }

        return $user;
    }

    protected function useSystemUser()
    {
        $systemUser = $this->entityManager->getEntity('User', 'system');
        if (!$systemUser) {
            throw new Error("System user is not found.");
        }

        $this->container->set('user', $systemUser);
    }

    protected function getLdapClient()
    {
        if (!isset($this->ldapClient)) {
            $options = $this->utils->getLdapClientOptions();

            try {
                $this->ldapClient = new LDAP\Client($options);
            } catch (\Exception $e) {
                $GLOBALS['log']->error('LDAP error: ' . $e->getMessage());
            }
        }

        return $this->ldapClient;
    }

    /**
     * Login by authorization token
     *
     * @param  string $username
     * @param  \Espo\Entities\AuthToken $authToken
     *
     * @return \Espo\Entities\User | null
     */
    protected function loginByToken($username, AuthToken $authToken = null)
    {
        if (!isset($authToken)) {
            return null;
        }

        $userId = $authToken->get('userId');
        $user = $this->entityManager->getEntity('User', $userId);

        $tokenUsername = $user->get('userName');
        if (strtolower($username) != strtolower($tokenUsername)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $GLOBALS['log']->alert(
                'Unauthorized access attempt for user ['.$username.'] from IP ['.$ip.']'
            );
            return null;
        }

        $user = $this->entityManager->getRepository('User')->findOne([
            'whereClause' => [
                'userName' => $username,
            ]
        ]);

        return $user;
    }

    /**
     * Login user with administrator rights
     *
     * @param  string $username
     * @param  string $password
     * @return \Espo\Entities\User | null
     */
    protected function adminLogin($username, $password)
    {
        $hash = $this->passwordHash->hash($password);

        $user = $this->entityManager->getRepository('User')->findOne([
            'whereClause' => [
                'userName' => $username,
                'password' => $hash,
                'type' => ['admin', 'super-admin']
            ]
        ]);

        return $user;
    }

    /**
     * Create Espo user with data gets from LDAP server
     *
     * @param  array $userData LDAP entity data
     * @param  boolean $isPortal Is portal user
     *
     * @return \Espo\Entities\User
     */
    protected function createUser(array $userData, $isPortal = false)
    {
        $GLOBALS['log']->info('Creating new user ...');
        $data = array();

        // show full array of the LDAP user
        $GLOBALS['log']->debug('LDAP: user data: ' .print_r($userData, true));

        //set values from ldap server
        $ldapFields = $this->loadFields('ldap');
        foreach ($ldapFields as $espo => $ldap) {
            $ldap = strtolower($ldap);
            if (isset($userData[$ldap][0])) {
                $GLOBALS['log']->debug('LDAP: Create a user wtih ['.$espo.'] = ['.$userData[$ldap][0].'].');
                $data[$espo] = $userData[$ldap][0];
            }
        }

        //set user fields
        if ($isPortal) {
            $userFields = $this->loadFields('portalUser');
            $userFields['type'] = 'portal';
        } else {
            $userFields = $this->loadFields('user');
        }

        foreach ($userFields as $fieldName => $fieldValue) {
            $data[$fieldName] = $fieldValue;
        }

        $this->useSystemUser();

        $user = $this->entityManager->getEntity('User');
        $user->set($data);
        $this->entityManager->saveEntity($user);

        return $this->entityManager->getEntity('User', $user->id);
    }

    /**
     * Find LDAP user DN by his username
     *
     * @param  string $username
     *
     * @return string | null
     */
    protected function findLdapUserDnByUsername($username)
    {
        $ldapClient = $this->getLdapClient();
        $options = $this->utils->getOptions();

        $loginFilterString = '';
        if (!empty($options['userLoginFilter'])) {
            $loginFilterString = $this->convertToFilterFormat($options['userLoginFilter']);
        }

        $searchString = '(&(objectClass='.$options['userObjectClass'].')('.$options['userNameAttribute'].'='.$username.')'.$loginFilterString.')';
        $result = $ldapClient->search($searchString, null, LDAP\Client::SEARCH_SCOPE_SUB);
        $GLOBALS['log']->debug('LDAP: user search string: "' . $searchString . '"');

        foreach ($result as $item) {
            return $item["dn"];
        }
    }

    /**
     * Check and convert filter item into LDAP format
     *
     * @param  string $filter E.g. "memberof=CN=externalTesters,OU=groups,DC=espo,DC=local"
     *
     * @return string
     */
    protected function convertToFilterFormat($filter)
    {
        $filter = trim($filter);
        if (substr($filter, 0, 1) != '(') {
            $filter = '(' . $filter;
        }
        if (substr($filter, -1) != ')') {
            $filter = $filter . ')';
        }
        return $filter;
    }

    /**
     * Load fields for a user
     *
     * @param  string $type
     *
     * @return array
     */
    protected function loadFields($type)
    {
        $options = $this->utils->getOptions();

        $typeMap = $type . 'FieldMap';

        $fields = array();
        foreach ($this->$typeMap as $fieldName => $fieldValue) {
            if (isset($options[$fieldValue])) {
                $fields[$fieldName] = $options[$fieldValue];
            }
        }

        return $fields;
    }
}