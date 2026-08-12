<?php

namespace WPHavenConnect\ContentTransfer;

use WPHavenConnect\Utilities\Environment;

/**
 * Who may use the per-post transfer UI (the editor sidebar panel and the list
 * table bulk controls).
 *
 * Deliberately *not* elevated-admin-only: pushing/pulling a single page is an
 * editorial action, so anyone WordPress already trusts to edit that content --
 * editors and above, plus authors on their own posts -- may do it. The heavier
 * whole-site tools (database transfer, uploads sync, search-replace, settings)
 * stay gated behind manage_options + ElevatedUsers.
 *
 * Every check here is called late (on an admin screen), never while plugins are
 * still loading: custom post types and their capabilities don't exist until
 * `init`, so an early check would silently answer "no" for products and other
 * CPTs.
 */
class TransferPermissions
{
    /**
     * Can the current user transfer this specific post? Authoritative check --
     * used by the AJAX handler.
     */
    public static function canEditPost(int $post_id): bool
    {
        $can = $post_id > 0 && current_user_can('edit_post', $post_id);

        return (bool) apply_filters('wphaven_content_transfer_user_can', $can, $post_id, null);
    }

    /**
     * Can the current user edit content of this post type at all? Used to decide
     * whether to render the UI on a screen, before any specific post is known.
     */
    public static function canEditPostType(string $post_type): bool
    {
        $type = $post_type !== '' ? get_post_type_object($post_type) : null;
        $cap  = $type && ! empty($type->cap->edit_posts) ? $type->cap->edit_posts : 'edit_posts';

        $can = current_user_can($cap);

        return (bool) apply_filters('wphaven_content_transfer_user_can', $can, 0, $post_type);
    }

    /**
     * Is transferring possible from this site at all? Production is the
     * destination, never the source of a manual transfer.
     */
    public static function uiAvailable(): bool
    {
        return ! Environment::is_production();
    }

    /**
     * Are there other environments to push to / pull from?
     */
    public static function hasTargets(): bool
    {
        return ! empty(Environments::selectableTargets());
    }
}
