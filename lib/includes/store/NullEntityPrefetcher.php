<?php

namespace Wikibase\Lib\Store;

use Wikibase\DataModel\Entity\EntityId;

/**
 * No-op EntityPrefetcher
 *
 * @since 0.5
 *
 * @license GNU GPL v2+
 * @author Marius Hoch < hoo@online.de >
 */
class NullEntityPrefetcher implements EntityPrefetcher {

	/**
	 * Prefetches data for a list of entity ids.
	 *
	 * @param EntityId[] $entityIds
	 */
	public function prefetch( array $entityIds ) {
	}

	/**
	 * Purges prefetched data about a given entity.
	 *
	 * @param EntityId $entityId
	 */
	public function purge( EntityId $entityId ) {
	}

	/**
	 * Purges all prefetched data.
	 */
	public function purgeAll() {
	}

}
