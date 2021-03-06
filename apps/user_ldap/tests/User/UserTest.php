<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Juan Pablo Villafáñez <jvillafanez@solidgear.es>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Roger Szabo <roger.szabo@web.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\User_LDAP\Tests\User;

use OCA\User_LDAP\Access;
use OCA\User_LDAP\Connection;
use OCA\User_LDAP\FilesystemHelper;
use OCA\User_LDAP\LogWrapper;
use OCA\User_LDAP\User\User;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\Image;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * Class UserTest
 *
 * @group DB
 *
 * @package OCA\User_LDAP\Tests\User
 */
class UserTest extends \Test\TestCase {
	/** @var  Access|\PHPUnit_Framework_MockObject_MockObject */
	protected $access;
	/** @var  Connection|\PHPUnit_Framework_MockObject_MockObject */
	protected $connection;
	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	protected $config;
	/** @var FilesystemHelper|\PHPUnit_Framework_MockObject_MockObject */
	protected $filesystemhelper;
	/** @var INotificationManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $notificationManager;
	/** @var IUserManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $userManager;
	/** @var Image|\PHPUnit_Framework_MockObject_MockObject */
	protected $image;
	/** @var IAvatarManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $avatarManager;
	/** @var LogWrapper|\PHPUnit_Framework_MockObject_MockObject */
	protected $log;
	/** @var string */
	protected $uid = 'alice';
	/** @var string */
	protected $dn  = 'uid=alice,dc=foo,dc=bar';
	/** @var User */
	protected $user;

	public function setUp() {
		parent::setUp();

		$this->connection = $this->createMock(Connection::class);

		$this->access = $this->createMock(Access::class);
		$this->access->connection = $this->connection;
		$this->access->expects($this->any())
			->method('getConnection')
			->willReturn($this->connection);

		$this->config = $this->createMock(IConfig::class);
		$this->filesystemhelper = $this->createMock(FilesystemHelper::class);
		$this->log = $this->createMock(LogWrapper::class);
		$this->avatarManager = $this->createMock(IAvatarManager::class);
		$this->image = $this->createMock(Image::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);

		$this->user = new User(
			$this->uid,
			$this->dn,
			$this->access,
			$this->config,
			$this->filesystemhelper,
			$this->image,
			$this->log,
			$this->avatarManager,
			$this->userManager,
			$this->notificationManager
		);
	}

	public function testGetDNandUsername() {
		$this->assertSame($this->dn, $this->user->getDN());
		$this->assertSame($this->uid, $this->user->getUsername());
	}

	public function testUpdateEmailProvided() {
		$this->connection->expects($this->once())
			->method('__get')
			->with($this->equalTo('ldapEmailAttribute'))
			->will($this->returnValue('email'));

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('email'))
			->will($this->returnValue(['alice@foo.bar']));

		$coreUser = $this->getMockBuilder(IUser::class)
			->disableOriginalConstructor()
			->getMock();
		$coreUser->expects($this->once())
			->method('setEMailAddress')
			->with('alice@foo.bar');

		$this->userManager->expects($this->any())
			->method('get')
			->willReturn($coreUser);

		$this->user->updateEmail();
	}

	public function testUpdateEmailNotProvided() {
		$this->connection->expects($this->once())
			->method('__get')
			->with($this->equalTo('ldapEmailAttribute'))
			->will($this->returnValue('email'));

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('email'))
			->will($this->returnValue(false));

		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->updateEmail();
	}

	public function testUpdateEmailNotConfigured() {
		$this->connection->expects($this->once())
			->method('__get')
			->with($this->equalTo('ldapEmailAttribute'))
			->will($this->returnValue(''));

		$this->access->expects($this->never())
			->method('readAttribute');

		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->updateEmail();
	}

	public function testUpdateQuotaAllProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['42 GB']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('42 GB');

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	public function testUpdateQuotaToDefaultAllProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['default']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('default');

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	public function testUpdateQuotaToNoneAllProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['none']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('none');

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	public function testUpdateQuotaDefaultProvided() {
		$this->connection->expects($this->at(0))
			->method('__get')
			->with($this->equalTo('ldapQuotaAttribute'))
			->will($this->returnValue('myquota'));
		$this->connection->expects($this->at(1))
			->method('__get')
			->with($this->equalTo('ldapQuotaDefault'))
			->will($this->returnValue('25 GB'));
		$this->connection->expects($this->exactly(2))
			->method('__get');

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(false));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('25 GB');

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	public function testUpdateQuotaIndividualProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['27 GB']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('27 GB');

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	public function testUpdateQuotaNoneProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(false));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->never())
			->method('setQuota');

		$this->userManager->expects($this->never())
			->method('get')
			->with($this->uid);

		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->updateQuota();
	}

	public function testUpdateQuotaNoneConfigured() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', ''],
				['ldapQuotaDefault', '']
			]);

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->never())
			->method('setQuota');

		$this->userManager->expects($this->never())
			->method('get');

		$this->access->expects($this->never())
			->method('readAttribute');

		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->updateQuota();
	}

	public function testUpdateQuotaFromValue() {
		$readQuota = '19 GB';

		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '']
			]);

		$this->access->expects($this->never())
			->method('readAttribute');

		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('setQuota')
			->with($readQuota);

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($user));

		$this->user->updateQuota($readQuota);
	}

	/**
	 * Unparseable quota will fallback to use the LDAP default
	 */
	public function testUpdateWrongQuotaAllProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '23 GB']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['42 GBwos']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->once())
			->method('setQuota')
			->with('23 GB');

		$this->userManager->expects($this->once())
			->method('get')
			->with($this->uid)
			->will($this->returnValue($coreUser));

		$this->user->updateQuota();
	}

	/**
	 * No user quota and wrong default will set 'default' as quota
	 */
	public function testUpdateWrongDefaultQuotaProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '23 GBwowowo']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(false));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->never())
			->method('setQuota');

		$this->userManager->expects($this->never())
			->method('get');

		$this->user->updateQuota();
	}

	/**
	 * Wrong user quota and wrong default will set 'default' as quota
	 */
	public function testUpdateWrongQuotaAndDefaultAllProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', 'myquota'],
				['ldapQuotaDefault', '23 GBwowowo']
			]);

		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('myquota'))
			->will($this->returnValue(['23 flush']));

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->never())
			->method('setQuota');

		$this->userManager->expects($this->never())
			->method('get');

		$this->user->updateQuota();
	}

	/**
	 * No quota attribute set and wrong default will set 'default' as quota
	 */
	public function testUpdateWrongDefaultQuotaNotProvided() {
		$this->connection->expects($this->exactly(2))
			->method('__get')
			->willReturnMap([
				['ldapQuotaAttribute', ''],
				['ldapQuotaDefault', '23 GBwowowo']
			]);

		$this->access->expects($this->never())
			->method('readAttribute');

		$coreUser = $this->createMock(IUser::class);
		$coreUser->expects($this->never())
			->method('setQuota');

		$this->userManager->expects($this->never())
			->method('get');

		$this->user->updateQuota();
	}

	//the testUpdateAvatar series also implicitely tests getAvatarImage
	public function testUpdateAvatarJpegPhotoProvided() {
		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('jpegphoto'))
			->will($this->returnValue(['this is a photo']));

		$this->image->expects($this->once())
			->method('loadFromBase64')
			->willReturn('imageResource');
		$this->image->expects($this->once())
			->method('valid')
			->will($this->returnValue(true));
		$this->image->expects($this->once())
			->method('width')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('height')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('centerCrop')
			->will($this->returnValue(true));

		$this->filesystemhelper->expects($this->once())
			->method('isLoaded')
			->will($this->returnValue(true));

		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())
			->method('set')
			->with($this->isInstanceOf($this->image));

		$this->avatarManager->expects($this->once())
			->method('getAvatar')
			->with($this->equalTo($this->uid))
			->will($this->returnValue($avatar));

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->user->updateAvatar();
	}

	public function testUpdateAvatarThumbnailPhotoProvided() {
		$this->access->expects($this->any())
			->method('readAttribute')
			->willReturnCallback(function($dn, $attr) {
				if($dn === $this->dn
					&& $attr === 'jpegphoto')
				{
					return false;
				} elseif($dn === $this->dn
					&& $attr === 'thumbnailphoto')
				{
					return ['this is a photo'];
				}
				return null;
			});

		$this->image->expects($this->once())
			->method('loadFromBase64')
			->willReturn('imageResource');
		$this->image->expects($this->once())
			->method('valid')
			->will($this->returnValue(true));
		$this->image->expects($this->once())
			->method('width')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('height')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('centerCrop')
			->will($this->returnValue(true));

		$this->filesystemhelper->expects($this->once())
			->method('isLoaded')
			->will($this->returnValue(true));

		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())
			->method('set')
			->with($this->isInstanceOf($this->image));

		$this->avatarManager->expects($this->once())
			->method('getAvatar')
			->with($this->equalTo($this->uid))
			->will($this->returnValue($avatar));

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->user->updateAvatar();
	}

	public function testUpdateAvatarCorruptPhotoProvided() {
		$this->access->expects($this->any())
			->method('readAttribute')
			->willReturnCallback(function($dn, $attr) {
				if($dn === $this->dn
					&& $attr === 'jpegphoto')
				{
					return false;
				} elseif($dn === $this->dn
					&& $attr === 'thumbnailphoto')
				{
					return ['this is a photo'];
				}
				return null;
			});

		$this->image->expects($this->once())
			->method('loadFromBase64')
			->willReturn(false);
		$this->image->expects($this->never())
			->method('valid');
		$this->image->expects($this->never())
			->method('width');
		$this->image->expects($this->never())
			->method('height');
		$this->image->expects($this->never())
			->method('centerCrop');

		$this->filesystemhelper->expects($this->never())
			->method('isLoaded');

		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->never())
			->method('set');

		$this->avatarManager->expects($this->never())
			->method('getAvatar');

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->user->updateAvatar();
	}

	public function testUpdateAvatarUnsupportedThumbnailPhotoProvided() {
		$this->access->expects($this->any())
			->method('readAttribute')
			->willReturnCallback(function($dn, $attr) {
				if($dn === $this->dn
					&& $attr === 'jpegphoto')
				{
					return false;
				} elseif($dn === $this->dn
					&& $attr === 'thumbnailphoto')
				{
					return ['this is a photo'];
				}
				return null;
			});

		$this->image->expects($this->once())
			->method('loadFromBase64')
			->willReturn('imageResource');
		$this->image->expects($this->once())
			->method('valid')
			->will($this->returnValue(true));
		$this->image->expects($this->once())
			->method('width')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('height')
			->will($this->returnValue(128));
		$this->image->expects($this->once())
			->method('centerCrop')
			->will($this->returnValue(true));

		$this->filesystemhelper->expects($this->once())
			->method('isLoaded')
			->will($this->returnValue(true));

		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())
			->method('set')
			->with($this->isInstanceOf($this->image))
			->willThrowException(new \Exception());

		$this->avatarManager->expects($this->once())
			->method('getAvatar')
			->with($this->equalTo($this->uid))
			->will($this->returnValue($avatar));

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->assertFalse($this->user->updateAvatar());
	}

	public function testUpdateAvatarNotProvided() {
		$this->access->expects($this->any())
			->method('readAttribute')
			->willReturnCallback(function($dn, $attr) {
				if($dn === $this->dn
					&& $attr === 'jpegPhoto')
				{
					return false;
				} elseif($dn === $this->dn
					&& $attr === 'thumbnailPhoto')
				{
					return false;
				}
				return null;
			});

		$this->image->expects($this->never())
			->method('valid');
		$this->image->expects($this->never())
			->method('width');
		$this->image->expects($this->never())
			->method('height');
		$this->image->expects($this->never())
			->method('centerCrop');

		$this->filesystemhelper->expects($this->never())
			->method('isLoaded');

		$this->avatarManager->expects($this->never())
			->method('getAvatar');

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->user->updateAvatar();
	}

	public function testUpdateBeforeFirstLogin() {
		$this->config->expects($this->at(0))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_FIRSTLOGIN),
				$this->equalTo(0))
			->will($this->returnValue(0));
		$this->config->expects($this->at(1))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_LASTREFRESH),
				$this->equalTo(0))
			->will($this->returnValue(0));
		$this->config->expects($this->exactly(2))
			->method('getUserValue');
		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->update();
	}

	public function testUpdateAfterFirstLogin() {
		$this->config->expects($this->at(0))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_FIRSTLOGIN),
				$this->equalTo(0))
			->will($this->returnValue(1));
		$this->config->expects($this->at(1))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_LASTREFRESH),
				$this->equalTo(0))
			->will($this->returnValue(0));
		$this->config->expects($this->exactly(2))
			->method('getUserValue');
		$this->config->expects($this->once())
			->method('setUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_LASTREFRESH),
				$this->anything())
			->will($this->returnValue(true));

		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$this->user->update();
	}

	public function testUpdateNoRefresh() {
		$this->config->expects($this->at(0))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_FIRSTLOGIN),
				$this->equalTo(0))
			->will($this->returnValue(1));
		$this->config->expects($this->at(1))
			->method('getUserValue')
			->with($this->equalTo($this->uid), $this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_LASTREFRESH),
				$this->equalTo(0))
			->will($this->returnValue(time() - 10));
		$this->config->expects($this->once())
			->method('getAppValue')
			->with($this->equalTo('user_ldap'),
				$this->equalTo('updateAttributesInterval'),
				$this->anything())
			->will($this->returnValue(1800));
		$this->config->expects($this->exactly(2))
			->method('getUserValue');
		$this->config->expects($this->never())
			->method('setUserValue');

		$this->user->update();
	}

	public function testMarkLogin() {
		$this->config->expects($this->once())
			->method('setUserValue')
			->with($this->equalTo($this->uid),
				$this->equalTo('user_ldap'),
				$this->equalTo(User::USER_PREFKEY_FIRSTLOGIN),
				$this->equalTo(1))
			->will($this->returnValue(true));

		$this->user->markLogin();
	}

	public function testGetAvatarImageProvided() {
		$this->access->expects($this->once())
			->method('readAttribute')
			->with($this->equalTo($this->dn),
				$this->equalTo('jpegphoto'))
			->will($this->returnValue(['this is a photo']));
		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$photo = $this->user->getAvatarImage();
		$this->assertSame('this is a photo', $photo);
		//make sure readAttribute is not called again but the already fetched
		//photo is returned
		$this->user->getAvatarImage();
	}

	public function testGetAvatarImageDisabled() {
		$this->access->expects($this->never())
			->method('readAttribute')
			->with($this->equalTo($this->dn), $this->anything());
		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn([]);

		$this->assertFalse($this->user->getAvatarImage());
	}

	public function imageDataProvider() {
		return [
			[ false, false ],
			[ 'corruptData', false ],
			[ 'validData', true ],
		];
	}

	public function testProcessAttributes() {
		$requiredMethods = array(
			'markRefreshTime',
			'updateQuota',
			'updateEmail',
			'composeAndStoreDisplayName',
			'storeLDAPUserName',
			'getHomePath',
			'updateAvatar'
		);

		/** @var User|\PHPUnit_Framework_MockObject_MockObject $userMock */
		$userMock = $this->getMockBuilder(User::class)
			->setConstructorArgs([
				$this->uid,
				$this->dn,
				$this->access,
				$this->config,
				$this->filesystemhelper,
				$this->image,
				$this->log,
				$this->avatarManager,
				$this->userManager,
				$this->notificationManager
			])
			->setMethods($requiredMethods)
			->getMock();

		$this->connection->setConfiguration(array(
			'homeFolderNamingRule' => 'homeDirectory'
		));
		$this->connection->expects($this->any())
			->method('__get')
			->will($this->returnCallback(function($name) {
				if($name === 'homeFolderNamingRule') {
					return 'attr:homeDirectory';
				}
				return $name;
			}));
		$this->connection->expects($this->any())
			->method('resolveRule')
			->with('avatar')
			->willReturn(['jpegphoto', 'thumbnailphoto']);

		$record = [
			strtolower($this->connection->ldapQuotaAttribute) => ['4096'],
			strtolower($this->connection->ldapEmailAttribute) => ['alice@wonderland.org'],
			strtolower($this->connection->ldapUserDisplayName) => ['Aaaaalice'],
			'uid' => [$this->uid],
			'homedirectory' => ['Alice\'s Folder'],
			'memberof' => ['cn=groupOne', 'cn=groupTwo'],
			'jpegphoto' => ['here be an image']
		];

		foreach($requiredMethods as $method) {
			$userMock->expects($this->once())
				->method($method);
		}
		\OC_Hook::clear();//disconnect irrelevant hooks 
		$userMock->processAttributes($record);
		/** @noinspection PhpUnhandledExceptionInspection */
		\OC_Hook::emit('OC_User', 'post_login', ['uid' => $this->uid]);
	}

	public function emptyHomeFolderAttributeValueProvider() {
		return array(
			'empty' => [''],
			'prefixOnly' => ['attr:'],
		);
	}

	/**
	 * @dataProvider emptyHomeFolderAttributeValueProvider
	 */
	public function testGetHomePathNotConfigured($attributeValue) {
		$this->connection->expects($this->any())
			->method('__get')
			->with($this->equalTo('homeFolderNamingRule'))
			->will($this->returnValue($attributeValue));

		$this->access->expects($this->never())
			->method('readAttribute');

		$this->config->expects($this->never())
			->method('getAppValue');

		/** @noinspection PhpUnhandledExceptionInspection */
		$this->assertFalse($this->user->getHomePath());
	}

	public function testGetHomePathConfiguredNotAvailableAllowed() {
		$this->connection->expects($this->any())
			->method('__get')
			->with($this->equalTo('homeFolderNamingRule'))
			->will($this->returnValue('attr:foobar'));

		$this->access->expects($this->once())
			->method('readAttribute')
			->will($this->returnValue(false));

		// asks for "enforce_home_folder_naming_rule"
		$this->config->expects($this->once())
			->method('getAppValue')
			->will($this->returnValue(false));

		/** @noinspection PhpUnhandledExceptionInspection */
		$this->assertFalse($this->user->getHomePath());
	}

	/**
	 * @expectedException \Exception
	 */
	public function testGetHomePathConfiguredNotAvailableNotAllowed() {
		$this->connection->expects($this->any())
			->method('__get')
			->with($this->equalTo('homeFolderNamingRule'))
			->will($this->returnValue('attr:foobar'));

		$this->access->expects($this->once())
			->method('readAttribute')
			->will($this->returnValue(false));

		// asks for "enforce_home_folder_naming_rule"
		$this->config->expects($this->once())
			->method('getAppValue')
			->will($this->returnValue(true));

		$this->user->getHomePath();
	}

	public function displayNameProvider() {
		return [
			['Roland Deschain', '', 'Roland Deschain', false],
			['Roland Deschain', '', 'Roland Deschain', true],
			['Roland Deschain', null, 'Roland Deschain', false],
			['Roland Deschain', 'gunslinger@darktower.com', 'Roland Deschain (gunslinger@darktower.com)', false],
			['Roland Deschain', 'gunslinger@darktower.com', 'Roland Deschain (gunslinger@darktower.com)', true],
		];
	}

	/**
	 * @dataProvider displayNameProvider
	 */
	public function testComposeAndStoreDisplayName($part1, $part2, $expected, $expectTriggerChange) {
		$this->config->expects($this->once())
			->method('setUserValue');
		$oldName = $expectTriggerChange ? 'xxGunslingerxx' : null;
		$this->config->expects($this->once())
			->method('getUserValue')
			->with($this->user->getUsername(), 'user_ldap', 'displayName', null)
			->willReturn($oldName);

		$ncUserObj = $this->createMock(\OC\User\User::class);
		if ($expectTriggerChange) {
			$ncUserObj->expects($this->once())
				->method('triggerChange')
				->with('displayName', $expected);
		} else {
			$ncUserObj->expects($this->never())
				->method('triggerChange');
		}
		$this->userManager->expects($this->once())
			->method('get')
			->willReturn($ncUserObj);

		$displayName = $this->user->composeAndStoreDisplayName($part1, $part2);
		$this->assertSame($expected, $displayName);
	}

	public function testComposeAndStoreDisplayNameNoOverwrite() {
		$displayName = 'Randall Flagg';
		$this->config->expects($this->never())
			->method('setUserValue');
		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn($displayName);

		$this->userManager->expects($this->never())
			->method('get'); // Implicit: no triggerChange can be called

		$composedDisplayName = $this->user->composeAndStoreDisplayName($displayName);
		$this->assertSame($composedDisplayName, $displayName);
	}

	public function testHandlePasswordExpiryWarningDefaultPolicy() {
		$this->connection->expects($this->any())
			->method('__get')
			->will($this->returnCallback(function($name) {
				if($name === 'ldapDefaultPPolicyDN') {
					return 'cn=default,ou=policies,dc=foo,dc=bar';
				}
				if($name === 'turnOnPasswordChange') {
					return '1';
				}
				return $name;
			}));

		$this->access->expects($this->any())
			->method('search')
			->will($this->returnCallback(function($filter, $base) {
				if($base === [$this->dn]) {
					return [
						[
							'pwdchangedtime' => [(new \DateTime())->sub(new \DateInterval('P28D'))->format('Ymdhis').'Z'],
							'pwdgraceusetime' => [],
						],
					];
				}
				if($base === ['cn=default,ou=policies,dc=foo,dc=bar']) {
					return [
						[
							'pwdmaxage' => ['2592000'],
							'pwdexpirewarning' => ['2591999'],
						],
					];
				}
				return [];
			}));

		$notification = $this->getMockBuilder(INotification::class)
			->disableOriginalConstructor()
			->getMock();
		$notification->expects($this->any())
			->method('setApp')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setUser')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setObject')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setDateTime')
			->will($this->returnValue($notification));

		$this->notificationManager->expects($this->exactly(2))
			->method('createNotification')
			->will($this->returnValue($notification));
		$this->notificationManager->expects($this->exactly(1))
			->method('notify');

		\OC_Hook::clear();//disconnect irrelevant hooks
		\OCP\Util::connectHook('OC_User', 'post_login', $this->user, 'handlePasswordExpiry');
		/** @noinspection PhpUnhandledExceptionInspection */
		\OC_Hook::emit('OC_User', 'post_login', ['uid' => $this->uid]);
	}

	public function testHandlePasswordExpiryWarningCustomPolicy() {
		$this->connection->expects($this->any())
			->method('__get')
			->will($this->returnCallback(function($name) {
				if($name === 'ldapDefaultPPolicyDN') {
					return 'cn=default,ou=policies,dc=foo,dc=bar';
				}
				if($name === 'turnOnPasswordChange') {
					return '1';
				}
				return $name;
			}));

		$this->access->expects($this->any())
			->method('search')
			->will($this->returnCallback(function($filter, $base) {
				if($base === [$this->dn]) {
					return [
						[
							'pwdpolicysubentry' => ['cn=custom,ou=policies,dc=foo,dc=bar'],
							'pwdchangedtime' => [(new \DateTime())->sub(new \DateInterval('P28D'))->format('Ymdhis').'Z'],
							'pwdgraceusetime' => [],
						]
					];
				}
				if($base === ['cn=custom,ou=policies,dc=foo,dc=bar']) {
					return [
						[
							'pwdmaxage' => ['2592000'],
							'pwdexpirewarning' => ['2591999'],
						]
					];
				}
				return [];
			}));

		$notification = $this->getMockBuilder(INotification::class)
			->disableOriginalConstructor()
			->getMock();
		$notification->expects($this->any())
			->method('setApp')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setUser')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setObject')
			->will($this->returnValue($notification));
		$notification->expects($this->any())
			->method('setDateTime')
			->will($this->returnValue($notification));

		$this->notificationManager->expects($this->exactly(2))
			->method('createNotification')
			->will($this->returnValue($notification));
		$this->notificationManager->expects($this->exactly(1))
			->method('notify');

		\OC_Hook::clear();//disconnect irrelevant hooks
		\OCP\Util::connectHook('OC_User', 'post_login', $this->user, 'handlePasswordExpiry');
		/** @noinspection PhpUnhandledExceptionInspection */
		\OC_Hook::emit('OC_User', 'post_login', ['uid' => $this->uid]);
	}
}
