<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issued certificates.
 */
final class CertificateRepository extends Repository {

	public function table(): string {
		// The BARE name — `Repository::full_table()` applies the prefix.
		return 'certificates';
	}

	public function entity_type(): string {
		return 'certificate';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'template_id', 'exam_id', 'attempt_id', 'user_id', 'guest_name', 'guest_email',
			'number', 'verify_token', 'recipient_name', 'score', 'max_score', 'percent',
			'issued_at', 'expires_at', 'revoked', 'revoked_at', 'revoked_by', 'revoke_reason',
			'parent_cert_id', 'snapshot_html', 'page_size', 'orientation', 'reminders_sent',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'number', 'recipient_name', 'issued_at', 'expires_at', 'percent' );
	}

	/**
	 * The certificate for an attempt, if one was issued.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_attempt( int $attempt_id ): ?array {
		return $this->query()
			->where( 't.attempt_id = %d', array( $attempt_id ) )
			->paginate( 1 )
			->first();
	}

	/**
	 * The certificate a verification token names.
	 *
	 * DELIBERATELY IGNORES THE TRASH SCOPE... no: it does not, and that is the point. A
	 * trashed certificate must not verify, because trashing one is how an administrator
	 * withdraws it in the rare case revoking is not enough. `query()` excludes trashed rows
	 * by default and this does not opt out.
	 *
	 * @param string $token Verification token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		return $this->query()
			->where( 't.verify_token = %s', array( $token ) )
			->paginate( 1 )
			->first();
	}

	/**
	 * Whether a number is already taken.
	 *
	 * Used to retry generation rather than to trust it. The column is UNIQUE, so a collision
	 * that slips past this still fails the insert instead of overwriting somebody's record.
	 *
	 * @param string $number Candidate number.
	 */
	public function number_exists( string $number ): bool {
		return $this->query()
			->with_trashed()
			->where( 't.number = %s', array( $number ) )
			->count() > 0;
	}

	/**
	 * Certificates belonging to one person, newest first.
	 *
	 * Matches a user id OR a guest email, for the same reason the privacy eraser does: the
	 * same person may have earned one signed in and another as a guest.
	 *
	 * @param int    $user_id User id, or 0.
	 * @param string $email   Email address, or an empty string.
	 * @param int    $limit   How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_person( int $user_id, string $email = '', int $limit = 50 ): array {
		$query = $this->query();

		if ( $user_id > 0 && '' !== $email ) {
			$query->where( '(t.user_id = %d OR t.guest_email = %s)', array( $user_id, $email ) );
		} elseif ( $user_id > 0 ) {
			$query->where( 't.user_id = %d', array( $user_id ) );
		} elseif ( '' !== $email ) {
			$query->where( 't.guest_email = %s', array( $email ) );
		} else {
			// Neither identity: match nothing rather than everything.
			$query->where( '1 = 0' );
		}

		return $query->order_by( 't.issued_at', 'DESC' )->paginate( $limit )->get();
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		foreach ( array( 'template_id', 'exam_id', 'attempt_id', 'parent_cert_id' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( array( 'score', 'max_score', 'percent' ) as $column ) {
			if ( isset( $row[ $column ] ) && null !== $row[ $column ] ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		if ( isset( $row['user_id'] ) ) {
			$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];
		}

		if ( isset( $row['revoked'] ) ) {
			$row['revoked'] = (bool) (int) $row['revoked'];
		}

		return $row;
	}
}
