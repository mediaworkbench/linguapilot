<?php
/**
 * Result of the runtime dependency check.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Environment;

/**
 * Immutable outcome of {@see \MediaWorkbench\MultilingualStoreAbilities\Dependencies::check()}.
 */
final class DependencyStatus {

	/**
	 * Creates a status.
	 *
	 * @param list<DependencyProblem> $problems Unmet dependencies, in a stable order.
	 */
	public function __construct( private readonly array $problems ) {}

	/**
	 * Whether abilities may be registered at all (no blocking problem).
	 */
	public function can_register_abilities(): bool {
		foreach ( $this->problems as $problem ) {
			if ( $problem->blocking ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether WooCommerce Multilingual is available for currency and sync-aware abilities.
	 */
	public function has_wcml(): bool {
		foreach ( $this->problems as $problem ) {
			if ( DependencyProblem::WCML === $problem->dependency ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * All unmet dependencies.
	 *
	 * @return list<DependencyProblem>
	 */
	public function problems(): array {
		return $this->problems;
	}
}
