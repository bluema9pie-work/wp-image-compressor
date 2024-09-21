<?php
/*
Plugin Name: WP Image Compressor
Description: 自動壓縮上傳的圖片,批次壓縮媒體庫,自動儲存外部圖片
Version: 1.0
Author: Aquarius
*/

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

class WP_Image_Compressor {
    private $compression_quality = 80;

    public function __construct() {
        // 圖片上傳時自動壓縮
        add_filter('wp_handle_upload', array($this, 'compress_uploaded_image'));
        
        // 添加批次壓縮頁面
        add_action('admin_menu', array($this, 'add_batch_compress_page'));
        
        // 修改這行，使用正確的鉤子來處理內容保存
        add_filter('content_save_pre', array($this, 'save_external_images'));

        // 添加這行，以處理 Gutenberg 編輯器的內容
        add_filter('rest_pre_insert_post', array($this, 'save_external_images_gutenberg'), 10, 2);

        // 添加AJAX處理
        add_action('wp_ajax_batch_compress_images', array($this, 'batch_compress_images'));
    }

    // 壓縮上傳的圖片
    public function compress_uploaded_image($file) {
        $image_path = $file['file'];
        $info = getimagesize($image_path);
        
        if ($info === false) {
            return $file;
        }

        $mime_type = $info['mime'];

        switch ($mime_type) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($image_path);
                imagejpeg($image, $image_path, $this->compression_quality);
                break;
            case 'image/png':
                $image = imagecreatefrompng($image_path);
                imagepng($image, $image_path, 9);
                break;
            default:
                return $file;
        }

        imagedestroy($image);

        // 添加一個動作，在生成縮圖後壓縮它們
        add_action('wp_generate_attachment_metadata', array($this, 'compress_attachment_thumbnails'), 10, 2);

        return $file;
    }

    // 壓縮附件的縮圖
    public function compress_attachment_thumbnails($metadata, $attachment_id) {
        if (isset($metadata['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_dir = $upload_dir['basedir'] . '/' . dirname($metadata['file']) . '/';
            
            foreach ($metadata['sizes'] as $size => $size_info) {
                $size_file_path = $base_dir . $size_info['file'];
                $this->compress_image($size_file_path);
            }
        }
        
        return $metadata;
    }

    // 添加批次壓縮頁面
    public function add_batch_compress_page() {
        add_menu_page('批次壓縮圖片', '批次壓縮圖片', 'manage_options', 'batch-compress', array($this, 'batch_compress_page'));
    }

    // 批次壓縮頁面內容
    public function batch_compress_page() {
        ?>
        <div class="wrap">
            <h1>批次壓縮圖片</h1>
            <button id="start-compression" class="button button-primary">開始壓縮</button>
            <div id="compression-progress"></div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#start-compression').click(function() {
                var data = {
                    'action': 'batch_compress_images',
                    'security': '<?php echo wp_create_nonce("batch_compress_images"); ?>'
                };
                $.post(ajaxurl, data, function(response) {
                    $('#compression-progress').html(response);
                });
            });
        });
        </script>
        <?php
    }

    // AJAX處理批次壓縮
    public function batch_compress_images() {
        check_ajax_referer('batch_compress_images', 'security');

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/png'),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
        );

        $query = new WP_Query($args);
        $compressed_count = 0;

        foreach ($query->posts as $image) {
            $file_path = get_attached_file($image->ID);
            if ($this->compress_image($file_path)) {
                // 更新所有尺寸的圖片
                $metadata = wp_get_attachment_metadata($image->ID);
                if (is_array($metadata) && isset($metadata['sizes'])) {
                    $upload_dir = wp_upload_dir();
                    $base_dir = $upload_dir['basedir'] . '/' . dirname($metadata['file']) . '/';
                    
                    foreach ($metadata['sizes'] as $size => $size_info) {
                        $size_file_path = $base_dir . $size_info['file'];
                        $this->compress_image($size_file_path);
                    }
                }
                
                // 重新生成縮圖和其他尺寸
                $metadata = wp_generate_attachment_metadata($image->ID, $file_path);
                wp_update_attachment_metadata($image->ID, $metadata);
                
                $compressed_count++;
            }
        }

        echo "已壓縮 {$compressed_count} 張圖片，包括所有尺寸的版本。";
        wp_die();
    }

    // 壓縮單張圖片
    private function compress_image($file_path) {
        $info = getimagesize($file_path);
        
        if ($info === false) {
            return false;
        }

        $mime_type = $info['mime'];

        switch ($mime_type) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($file_path);
                if ($image !== false) {
                    imagejpeg($image, $file_path, $this->compression_quality);
                    imagedestroy($image);
                    return true;
                }
                break;
            case 'image/png':
                $image = imagecreatefrompng($file_path);
                if ($image !== false) {
                    imagepng($image, $file_path, 9);
                    imagedestroy($image);
                    return true;
                }
                break;
        }

        return false;
    }

    // 修改自動儲存外部圖片方法
    public function save_external_images($content) {
        if (empty($content)) {
            return $content;
        }

        $content = $this->process_external_images($content);

        return $content;
    }

    // 添加處理 Gutenberg 編輯器內容的方法
    public function save_external_images_gutenberg($prepared_post, $request) {
        if (!empty($prepared_post->post_content)) {
            $prepared_post->post_content = $this->process_external_images($prepared_post->post_content);
        }
        return $prepared_post;
    }

    // 新增處理外部圖片的核心方法
    private function process_external_images($content) {
        if (!preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches)) {
            return $content;
        }

        foreach ($matches[1] as $image_url) {
            if (strpos($image_url, home_url()) === false) {
                $local_image = $this->download_external_image($image_url);
                if ($local_image) {
                    $content = str_replace($image_url, $local_image, $content);
                }
            }
        }

        return $content;
    }

    // 下載外部圖片
    private function download_external_image($image_url) {
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        
        if ($image_data === false) {
            return false;
        }

        $filename = basename($image_url);
        $unique_filename = wp_unique_filename($upload_dir['path'], $filename);
        $filepath = $upload_dir['path'] . '/' . $unique_filename;

        file_put_contents($filepath, $image_data);

        // 壓縮下載的圖片
        $this->compress_image($filepath);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $filepath);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        
        // 壓縮生成的縮圖
        $this->compress_attachment_thumbnails($attach_data, $attach_id);
        
        wp_update_attachment_metadata($attach_id, $attach_data);

        return wp_get_attachment_url($attach_id);
    }
}

// 初始化外掛
new WP_Image_Compressor();