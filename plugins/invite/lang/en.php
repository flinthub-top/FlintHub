<?php
/**
 * Invite Registration Plugin Language Pack — English
 * @file plugins/invite/lang/en.php
 */
return [
    'plugin.invite.title'       => 'My Invites',
    'plugin.invite.nav'         => 'Invite Registration',
    'plugin.invite.valid'       => 'Valid Invites',
    'plugin.invite.used'        => 'Used',
    'plugin.invite.cur_points'  => 'Current Points',
    'plugin.invite.generate'    => 'Generate Invite',
    'plugin.invite.cost_points' => 'Points Cost',
    'plugin.invite.expires_days'=> 'Validity (days)',
    'plugin.invite.generate_btn'=> 'Generate Invite',
    'plugin.invite.records'     => 'Invite Records',
    'plugin.invite.empty'       => 'You have not generated any invite codes yet',
    'plugin.invite.th_code'     => 'Code',
    'plugin.invite.th_cost'     => 'Points Cost',
    'plugin.invite.th_status'   => 'Status',
    'plugin.invite.th_invitee'  => 'Invitee',
    'plugin.invite.th_created'  => 'Created At',
    'plugin.invite.status_used' => '✓ Used',
    'plugin.invite.status_expired' => 'Expired',
    'plugin.invite.status_valid'   => '● Valid',

    // Controller messages
    'plugin.invite.err_points'  => 'Not enough points. You have {cur}, need {need}',
    'plugin.invite.success'     => 'Invite code <strong>{code}</strong> generated, costing {cost} points, valid for {days} days',
    'plugin.invite.err_gen'     => 'Generation failed, please try again later',

    // Registration hook
    'plugin.invite.reg_required' => 'Invite registration is enabled. Please enter an invite code',
    'plugin.invite.reg_invalid'  => 'Invalid or expired invite code',
    'plugin.invite.field_label'  => 'Invite Code',
    'plugin.invite.field_ph'     => 'Enter invite code',
];
