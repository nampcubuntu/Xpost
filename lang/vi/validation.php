<?php

declare(strict_types=1);

return [
    'uploaded' => 'Không tải được tệp lên. Thường là do tệp vượt quá giới hạn của PHP (upload_max_filesize / post_max_size) chứ không phải giới hạn của Xpost.',

    'custom' => [
        'post' => [
            'empty' => 'Bài đăng phải có nội dung văn bản hoặc ít nhất một tệp phương tiện.',
            'needs_time' => 'Hãy chọn thời điểm đăng, hoặc chuyển sang chế độ đăng ngay.',
            'needs_window' => 'Chế độ rải/ngẫu nhiên cần có thời điểm kết thúc, độ lệch, hoặc giới hạn mỗi phút.',
            'needs_variants' => 'Chiến lược nội dung này yêu cầu ít nhất một biến thể.',
            'too_long' => 'Nội dung dài :weight ký tự (theo cách đếm của X), vượt giới hạn :max.',
            'too_long_with_url' => 'Sau khi cộng thêm link đích, nội dung vượt quá 280 ký tự.',
            'spintax_unbalanced' => 'Cú pháp spintax bị lệch dấu ngoặc { }.',
            'spintax_single_option' => 'Nhóm spintax cần ít nhất hai lựa chọn, phân tách bằng dấu |.',
        ],
    ],
];
