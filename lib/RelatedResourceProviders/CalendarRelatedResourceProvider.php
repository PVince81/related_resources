<?php

declare(strict_types=1);


/**
 * Nextcloud - Related Resources
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2022
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\RelatedResources\RelatedResourceProviders;


use Exception;
use OCA\Circles\CirclesManager;
use OCA\Circles\Model\FederatedUser;
use OCA\Circles\Model\Member;
use OCA\RelatedResources\Db\CalendarShareRequest;
use OCA\RelatedResources\IRelatedResource;
use OCA\RelatedResources\IRelatedResourceProvider;
use OCA\RelatedResources\Model\CalendarShare;
use OCA\RelatedResources\Model\RelatedResource;
use OCA\RelatedResources\Tools\Traits\TArrayTools;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;

class CalendarRelatedResourceProvider implements IRelatedResourceProvider {
	use TArrayTools;

	private const PROVIDER_ID = 'calendar';

	private IRootFolder $rootFolder;
	private IURLGenerator $urlGenerator;
	private CalendarShareRequest $calendarShareRequest;
	private CirclesManager $circlesManager;

	public function __construct(
		IRootFolder $rootFolder,
		IURLGenerator $urlGenerator,
		CalendarShareRequest $calendarShareRequest
	) {
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->calendarShareRequest = $calendarShareRequest;
		$this->circlesManager = \OC::$server->get(CirclesManager::class);
	}

	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}

	public function loadWeightCalculator(): array {
		return [];
	}


	/**
	 * @param string $itemId
	 *
	 * @return FederatedUser[]
	 */
	public function getSharesRecipients(string $itemId): array {
		$itemId = (int)$itemId;
		if ($itemId < 1) {
			return [];
		}

		$shares = $this->calendarShareRequest->getSharesByItemId($itemId);
		$this->generateSingleIds($shares);

		return array_filter(
			array_map(function (CalendarShare $share): ?FederatedUser {
				return $share->getEntity();
			}, $shares)
		);
	}


	/**
	 * @param FederatedUser $entity
	 *
	 * @return IRelatedResource[]
	 */
	public function getRelatedToEntity(FederatedUser $entity): array {
		switch ($entity->getBasedOn()->getSource()) {
			case Member::TYPE_USER:
				$shares = $this->calendarShareRequest->getSharesToUser($entity->getUserId());

				return [];

			case Member::TYPE_GROUP:
				$shares = $this->calendarShareRequest->getSharesToGroup($entity->getUserId());
				break;

			// not supported yet by calendar
//			case Member::TYPE_CIRCLE:
//				$shares = $this->calendarShareRequest->getSharesToCircle($entity->getSingleId());
//				break;

			default:
				return [];
		}
//
		$related = [];
		foreach ($shares as $share) {
			$related[] = $this->convertToRelatedResource($share);
		}

		return $related;
	}


	private function convertToRelatedResource(CalendarShare $share): IRelatedResource {
		$related = new RelatedResource(self::PROVIDER_ID, (string)$share->getCalendarId());
		$related->setTitle($share->getEventSummary() . ' on ' . date('F j, Y', $share->getEventDate()));
		$related->setSubtitle('Calendar Event (' . $share->getCalendarName() . ')');
		$related->setTooltip(
			$share->getCalendarName() . '/' . $share->getEventSummary() . ' on ' . $share->getEventSummary()
		);
		$related->setRange(1);

		try {
			$related->setLinkCreator($this->extractEntity($share->getCalendarPrincipalUri())->getSingleId());
		} catch (Exception $e) {
		}
//		$related->setLinkCreation($share->getShareTime());
		$related->setUrl(
			$this->urlGenerator->linkToRouteAbsolute(
				'calendar.view.indexview.timerange',
				[
					'view' => 'timeGridDay',
					'timeRange' => date('Y-m-d', $share->getEventDate())
				]
			)
		);

		return $related;
	}


	/**
	 * @param CalendarShare[] $shares
	 */
	private function generateSingleIds(array $shares): void {
		foreach ($shares as $share) {
			$this->generateSingleId($share);
		}
	}

	/**
	 * @param CalendarShare $share
	 */
	private function generateSingleId(CalendarShare $share): void {
		try {
			$share->setEntity($this->extractEntity($share->getSharePrincipalUri()));
		} catch (Exception $e) {
		}
	}


	private function extractEntity(string $principalUri): FederatedUser {
		[$shareType, $recipient] = explode('/', substr($principalUri, 11), 2);

		switch ($shareType) {
			case 'users':
				$type = Member::TYPE_USER;
				break;

			case 'groups':
				$type = Member::TYPE_GROUP;
				break;

			case 'circles': // not supported yet by Calendar
				$type = Member::TYPE_SINGLE;
				break;

			default:
				throw new Exception();
		}

		return $this->circlesManager->getFederatedUser($recipient, $type);
	}

}
