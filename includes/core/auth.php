<?php
/**
 * Access helpers for the plugin.
 *
 * NOTE: These functions are intentionally kept as procedural `wc_suf_*`
 * functions for backward compatibility with existing callbacks/integrations.
 */
function wc_suf_current_user_is_pos_manager(){
    if ( ! is_user_logged_in() ) return false;
    $user = wp_get_current_user();
    $roles = (array) ( $user->roles ?? [] );
    return in_array( 'formeditor', $roles, true )
        || in_array( 'marjoo', $roles, true );
}

function wc_suf_current_user_has_role( $role ){
    if ( ! is_user_logged_in() ) return false;
    $user = wp_get_current_user();
    $roles = (array) ( $user->roles ?? [] );
    return in_array( (string) $role, $roles, true );
}

/**
 * Compute allowed operation slugs for current user.
 *
 * This centralizes role-based operation permissions that were previously
 * duplicated in multiple modules. Returned values are intentionally identical
 * to existing behavior.
 *
 * @return string[]
 */
function wc_suf_get_allowed_ops_for_current_user() {
    $is_marjoo = wc_suf_current_user_has_role( 'marjoo' );
    $has_formeditor = wc_suf_current_user_has_role( 'formeditor' );
    $allowed_ops = $has_formeditor ? ['in','out','transfer','return','onlyLabel'] : [];
    if ( $is_marjoo ) {
        $allowed_ops[] = 'return';
    }

    return array_values( array_unique( $allowed_ops ) );
}

/**
 * Whether current user is a marjoo-only user (without formeditor).
 *
 * @return bool
 */
function wc_suf_is_marjoo_only_user() {
    return wc_suf_current_user_has_role( 'marjoo' )
        && ! wc_suf_current_user_has_role( 'formeditor' );
}
