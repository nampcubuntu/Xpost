<?php

declare(strict_types=1);

return [

    'account_status' => [
        'active' => 'Hoạt động',
        'needs_reauth' => 'Cần xác thực lại',
        'rate_limited' => 'Bị giới hạn tần suất',
        'suspended' => 'Bị X khoá',
        'disabled' => 'Đã tắt',
        'error' => 'Lỗi',
    ],

    'post_status' => [
        'draft' => 'Nháp',
        'scheduled' => 'Đã lên lịch',
        'publishing' => 'Đang đăng',
        'published' => 'Đã đăng',
        'partially_failed' => 'Đăng một phần',
        'failed' => 'Thất bại',
        'cancelled' => 'Đã huỷ',
    ],

    'target_status' => [
        'pending' => 'Chờ đăng',
        'queued' => 'Trong hàng đợi',
        'publishing' => 'Đang gửi',
        'published' => 'Đã đăng',
        'failed' => 'Thất bại',
        'skipped' => 'Bỏ qua',
        'cancelled' => 'Đã huỷ',
    ],

    'reply_settings' => [
        'everyone' => 'Mọi người đều có thể trả lời',
        'following' => 'Tài khoản bạn theo dõi',
        'mentionedUsers' => 'Chỉ người được nhắc đến',
        'subscribers' => 'Người đăng ký',
        'verified' => 'Tài khoản đã xác minh',
    ],

    'errors' => [
        'post_not_editable' => 'Bài đăng đang ở trạng thái ":status" nên không thể sửa hoặc đăng lại.',
        'app_has_accounts' => 'Vẫn còn :count tài khoản gắn với app này. Hãy chuyển chúng sang app khác trước.',
        'proxy_in_use' => 'Proxy đang được tài khoản sử dụng. Gỡ gán trước khi xoá.',
        'media_in_use' => 'Tệp này đang được dùng trong một bài đăng.',
        'not_a_member' => 'Bạn không thuộc nhóm làm việc này.',
        'no_app_capacity' => 'Không còn X App nào đủ hạn mức. Thêm app vào pool hoặc nâng tier.',
    ],

];
