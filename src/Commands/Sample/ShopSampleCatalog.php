<?php

namespace GP247\Shop\Commands\Sample;

/**
 * Demo catalog content for `gp247:shop-sample`: an electronics store whose categories,
 * products, brands and suppliers match the hosted sample photos one to one.
 *
 * WHY the photo numbers are listed per row instead of handed out in rotation: the
 * hosted photos each show one specific device (product-1 is a pair of headphones,
 * product-2 a laptop…), so only an explicit mapping keeps every product inside the
 * category that matches its photo.
 *
 * Photo numbers refer to the hosted sets
 * https://static.gp247.net/file/s-cart/product/product-<n>.png and
 * https://static.gp247.net/file/s-cart/<kind>/<kind>-<n>.png (kind: cate, brand, supplier).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005
 */
final class ShopSampleCatalog
{
    /**
     * Number of hosted sample product photos (product-1.png … product-N.png).
     */
    public const PRODUCT_IMAGE_COUNT = 36;

    /**
     * Number of hosted sample photos in each catalog set (cate, brand, supplier).
     */
    public const CATALOG_IMAGE_COUNT = 10;

    /**
     * Root categories, each with its subcategories and the products of every subcategory.
     *
     * Products are attached to subcategories only: a root category page already lists
     * the products of its subcategories.
     *
     * @return array<int, array{alias: string, image: int, descriptions: array<string, array{name: string, keyword: string, description: string}>, children: array<int, array<string, mixed>>}>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    public static function categories(): array
    {
        return [
            [
                'alias' => 'may-tinh-van-phong',
                'image' => 1,
                'descriptions' => self::texts(
                    ['Máy tính & Văn phòng', 'may tinh, van phong', 'Máy tính, màn hình và thiết bị văn phòng'],
                    ['Computers & Office', 'computers, office', 'Computers, monitors and office equipment'],
                    ['パソコン・オフィス', 'パソコン, オフィス', 'パソコン・モニター・オフィス機器'],
                ),
                'children' => [
                    [
                        'alias' => 'laptop-may-tinh',
                        'image' => 1,
                        'descriptions' => self::texts(
                            ['Laptop & Máy tính', 'laptop, may tinh, man hinh', 'Laptop, máy tính để bàn, linh kiện và màn hình'],
                            ['Laptops & Computers', 'laptop, computer, monitor', 'Laptops, desktop PCs, components and monitors'],
                            ['ノートPC・パソコン', 'ノートPC, パソコン, モニター', 'ノートPC・デスクトップPC・パーツ・モニター'],
                        ),
                        'products' => [
                            self::product(2, 'Laptop mỏng nhẹ 14 inch', '14-inch Thin and Light Laptop', '14インチ薄型軽量ノートPC'),
                            self::product(32, 'Máy tính mini để bàn', 'Mini Desktop PC', 'ミニデスクトップPC'),
                            self::product(33, 'Card đồ hoạ ba quạt', 'Triple-Fan Graphics Card', 'トリプルファン搭載グラフィックボード'),
                            self::product(13, 'Màn hình cong 27 inch', '27-inch Curved Monitor', '27インチ曲面モニター'),
                            self::product(36, 'Màn hình văn phòng 24 inch', '24-inch Office Monitor', '24インチオフィスモニター'),
                        ],
                    ],
                    [
                        'alias' => 'phu-kien-may-tinh',
                        'image' => 7,
                        'descriptions' => self::texts(
                            ['Phụ kiện máy tính', 'ban phim, chuot, webcam, may in', 'Bàn phím, chuột, webcam, thiết bị mạng và máy in'],
                            ['Computer Accessories', 'keyboard, mouse, webcam, printer', 'Keyboards, mice, webcams, networking and printers'],
                            ['パソコン周辺機器', 'キーボード, マウス, ウェブカメラ, プリンター', 'キーボード・マウス・ウェブカメラ・ネットワーク機器・プリンター'],
                        ),
                        'products' => [
                            self::product(8, 'Bàn phím cơ RGB', 'RGB Mechanical Keyboard', 'RGBメカニカルキーボード'),
                            self::product(9, 'Chuột không dây công thái học', 'Ergonomic Wireless Mouse', 'エルゴノミクスワイヤレスマウス'),
                            self::product(20, 'Webcam Full HD', 'Full HD Webcam', 'フルHDウェブカメラ'),
                            self::product(22, 'Router Wi-Fi 6 tốc độ cao', 'High-Speed Wi-Fi 6 Router', '高速Wi-Fi 6ルーター'),
                            self::product(34, 'Máy in đa năng', 'All-in-One Printer', '複合機プリンター'),
                        ],
                    ],
                ],
            ],
            [
                'alias' => 'di-dong-thiet-bi-deo',
                'image' => 2,
                'descriptions' => self::texts(
                    ['Di động & Thiết bị đeo', 'di dong, thiet bi deo', 'Điện thoại, máy tính bảng, thiết bị đeo và phụ kiện sạc'],
                    ['Mobile & Wearables', 'mobile, wearables', 'Phones, tablets, wearables and charging accessories'],
                    ['モバイル・ウェアラブル', 'モバイル, ウェアラブル', 'スマートフォン・タブレット・ウェアラブル・充電アクセサリー'],
                ),
                'children' => [
                    [
                        'alias' => 'dien-thoai-may-tinh-bang',
                        'image' => 2,
                        'descriptions' => self::texts(
                            ['Điện thoại & Máy tính bảng', 'dien thoai, may tinh bang', 'Điện thoại thông minh, máy tính bảng và máy đọc sách'],
                            ['Phones & Tablets', 'phone, tablet, e-reader', 'Smartphones, tablets and e-readers'],
                            ['スマートフォン・タブレット', 'スマートフォン, タブレット', 'スマートフォン・タブレット・電子書籍リーダー'],
                        ),
                        'products' => [
                            self::product(3, 'Điện thoại thông minh 6,7 inch', '6.7-inch Smartphone', '6.7インチスマートフォン'),
                            self::product(4, 'Máy tính bảng kèm bút cảm ứng', 'Tablet with Stylus', 'タッチペン付きタブレット'),
                            self::product(26, 'Máy đọc sách màn hình e-ink', 'E-ink E-reader', '電子ペーパー電子書籍リーダー'),
                        ],
                    ],
                    [
                        'alias' => 'thiet-bi-deo-vr',
                        'image' => 4,
                        'descriptions' => self::texts(
                            ['Thiết bị đeo & VR', 'dong ho thong minh, kinh vr', 'Đồng hồ thông minh, vòng đeo và kính thực tế ảo'],
                            ['Wearables & VR', 'smartwatch, vr headset', 'Smartwatches, fitness bands and VR headsets'],
                            ['ウェアラブル・VR', 'スマートウォッチ, VR', 'スマートウォッチ・活動量計・VRヘッドセット'],
                        ),
                        'products' => [
                            self::product(6, 'Đồng hồ thông minh theo dõi sức khoẻ', 'Health Tracking Smartwatch', 'ヘルスケア対応スマートウォッチ'),
                            self::product(15, 'Kính thực tế ảo VR', 'VR Headset', 'VRヘッドセット'),
                        ],
                    ],
                    [
                        'alias' => 'sac-luu-tru',
                        'image' => 8,
                        'descriptions' => self::texts(
                            ['Sạc & Lưu trữ', 'sac du phong, cu sac, ssd, usb', 'Pin sạc dự phòng, củ sạc, ổ cứng di động và USB'],
                            ['Charging & Storage', 'power bank, charger, ssd, usb', 'Power banks, chargers, portable drives and USB sticks'],
                            ['充電・ストレージ', 'モバイルバッテリー, 充電器, SSD, USB', 'モバイルバッテリー・充電器・ポータブルドライブ・USBメモリ'],
                        ),
                        'products' => [
                            self::product(5, 'Pin sạc dự phòng 20.000 mAh', '20,000 mAh Power Bank', '20,000mAhモバイルバッテリー'),
                            self::product(31, 'Củ sạc nhanh GaN 140W', '140W GaN Fast Charger', '140W GaN急速充電器'),
                            self::product(18, 'Ổ cứng SSD di động 2TB', '2TB Portable SSD', '2TBポータブルSSD'),
                            self::product(29, 'USB 1TB tốc độ cao', '1TB High-Speed USB Flash Drive', '1TB高速USBメモリ'),
                        ],
                    ],
                ],
            ],
            [
                'alias' => 'am-thanh-giai-tri',
                'image' => 3,
                'descriptions' => self::texts(
                    ['Âm thanh & Giải trí', 'am thanh, giai tri, gaming', 'Tai nghe, loa, thiết bị chơi game và giải trí tại gia'],
                    ['Audio & Entertainment', 'audio, entertainment, gaming', 'Headphones, speakers, gaming gear and home entertainment'],
                    ['オーディオ・エンタメ', 'オーディオ, エンタメ, ゲーム', 'ヘッドホン・スピーカー・ゲーム機器・ホームエンタメ'],
                ),
                'children' => [
                    [
                        'alias' => 'am-thanh',
                        'image' => 3,
                        'descriptions' => self::texts(
                            ['Âm thanh', 'tai nghe, loa, micro', 'Tai nghe, loa và micro thu âm'],
                            ['Audio', 'headphones, speaker, microphone', 'Headphones, speakers and microphones'],
                            ['オーディオ', 'ヘッドホン, スピーカー, マイク', 'ヘッドホン・スピーカー・マイク'],
                        ),
                        'products' => [
                            self::product(1, 'Tai nghe chụp tai chống ồn', 'Noise-Cancelling Over-Ear Headphones', 'ノイズキャンセリングヘッドホン'),
                            self::product(7, 'Tai nghe không dây True Wireless', 'True Wireless Earbuds', '完全ワイヤレスイヤホン'),
                            self::product(28, 'Micro thu âm USB', 'USB Studio Microphone', 'USBコンデンサーマイク'),
                            self::product(17, 'Loa Bluetooth đèn LED', 'Bluetooth Speaker with LED Light', 'LEDライト付きBluetoothスピーカー'),
                        ],
                    ],
                    [
                        'alias' => 'gaming',
                        'image' => 5,
                        'descriptions' => self::texts(
                            ['Gaming', 'gaming, tay cam, may choi game', 'Tay cầm, máy chơi game cầm tay và tai nghe gaming'],
                            ['Gaming', 'gaming, controller, console', 'Controllers, handheld consoles and gaming headsets'],
                            ['ゲーミング', 'ゲーム, コントローラー, ゲーム機', 'コントローラー・携帯型ゲーム機・ゲーミングヘッドセット'],
                        ),
                        'products' => [
                            self::product(12, 'Tay cầm chơi game không dây', 'Wireless Game Controller', 'ワイヤレスゲームコントローラー'),
                            self::product(19, 'Máy chơi game cầm tay', 'Handheld Game Console', '携帯型ゲーム機'),
                            self::product(21, 'Tai nghe gaming có micro', 'Gaming Headset with Mic', 'マイク付きゲーミングヘッドセット'),
                        ],
                    ],
                    [
                        'alias' => 'tv-giai-tri-tai-gia',
                        'image' => 10,
                        'descriptions' => self::texts(
                            ['TV & Giải trí tại gia', 'tv, may chieu, loa thanh', 'TV, máy chiếu và loa thanh'],
                            ['TV & Home Entertainment', 'tv, projector, soundbar', 'TVs, projectors and soundbars'],
                            ['テレビ・ホームエンタメ', 'テレビ, プロジェクター, サウンドバー', 'テレビ・プロジェクター・サウンドバー'],
                        ),
                        'products' => [
                            self::product(23, 'Smart TV 4K 55 inch', '55-inch 4K Smart TV', '55インチ4Kスマートテレビ'),
                            self::product(25, 'Máy chiếu mini di động', 'Portable Mini Projector', 'ポータブルミニプロジェクター'),
                            self::product(35, 'Loa thanh soundbar', 'Soundbar', 'サウンドバー'),
                        ],
                    ],
                ],
            ],
            [
                'alias' => 'may-anh-nha-thong-minh',
                'image' => 6,
                'descriptions' => self::texts(
                    ['Máy ảnh & Nhà thông minh', 'may anh, flycam, nha thong minh', 'Máy ảnh, flycam và thiết bị nhà thông minh'],
                    ['Cameras & Smart Home', 'camera, drone, smart home', 'Cameras, drones and smart home devices'],
                    ['カメラ・スマートホーム', 'カメラ, ドローン, スマートホーム', 'カメラ・ドローン・スマートホーム機器'],
                ),
                'children' => [
                    [
                        'alias' => 'may-anh-flycam',
                        'image' => 6,
                        'descriptions' => self::texts(
                            ['Máy ảnh & Flycam', 'may anh, flycam, camera hanh trinh', 'Máy ảnh, flycam, camera hành trình và gimbal'],
                            ['Cameras & Drones', 'camera, drone, action camera', 'Cameras, drones, action cameras and gimbals'],
                            ['カメラ・ドローン', 'カメラ, ドローン, アクションカメラ', 'カメラ・ドローン・アクションカメラ・ジンバル'],
                        ),
                        'products' => [
                            self::product(11, 'Máy ảnh mirrorless', 'Mirrorless Camera', 'ミラーレスカメラ'),
                            self::product(14, 'Flycam quay 4K', '4K Camera Drone', '4Kカメラ搭載ドローン'),
                            self::product(16, 'Camera hành trình chống nước', 'Waterproof Action Camera', '防水アクションカメラ'),
                            self::product(27, 'Gimbal chống rung cho điện thoại', 'Smartphone Gimbal Stabilizer', 'スマートフォン用ジンバル'),
                        ],
                    ],
                    [
                        'alias' => 'nha-thong-minh',
                        'image' => 9,
                        'descriptions' => self::texts(
                            ['Nhà thông minh', 'nha thong minh, robot hut bui, camera', 'Robot hút bụi, camera an ninh và loa thông minh'],
                            ['Smart Home', 'smart home, robot vacuum, camera', 'Robot vacuums, security cameras and smart speakers'],
                            ['スマートホーム', 'スマートホーム, ロボット掃除機, 防犯カメラ', 'ロボット掃除機・防犯カメラ・スマートスピーカー'],
                        ),
                        'products' => [
                            self::product(24, 'Robot hút bụi lau nhà', 'Robot Vacuum and Mop', '水拭き対応ロボット掃除機'),
                            self::product(30, 'Camera an ninh Wi-Fi', 'Wi-Fi Security Camera', 'Wi-Fi防犯カメラ'),
                            self::product(10, 'Loa thông minh trợ lý giọng nói', 'Smart Speaker with Voice Assistant', '音声アシスタント搭載スマートスピーカー'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sample brands; each name matches the logo of its hosted photo.
     *
     * Fictional names on the reserved `.example` domain so the demo never shows a real trademark.
     *
     * @return array<int, array{name: string, alias: string, image: int, url: string}>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    public static function brands(): array
    {
        return [
            ['name' => 'Voltiq', 'alias' => 'voltiq', 'image' => 1, 'url' => 'https://voltiq.example'],
            ['name' => 'Aurion', 'alias' => 'aurion', 'image' => 2, 'url' => 'https://aurion.example'],
            ['name' => 'Nexora', 'alias' => 'nexora', 'image' => 3, 'url' => 'https://nexora.example'],
        ];
    }

    /**
     * Sample suppliers; each name matches the logo of its hosted photo.
     *
     * @return array<int, array{name: string, alias: string, image: int, email: string, phone: string, address: string, url: string}>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    public static function suppliers(): array
    {
        return [
            [
                'name' => 'Lumora Distribution', 'alias' => 'lumora-distribution', 'image' => 1,
                'email' => 'contact@lumora.example', 'phone' => '0123456789',
                'address' => '123 Lumora Street', 'url' => 'https://lumora.example',
            ],
            [
                'name' => 'Techvanta Supply Chain', 'alias' => 'techvanta-supply-chain', 'image' => 2,
                'email' => 'contact@techvanta.example', 'phone' => '0987654321',
                'address' => '456 Techvanta Street', 'url' => 'https://techvanta.example',
            ],
            [
                'name' => 'Orbiqa Trading Co.', 'alias' => 'orbiqa-trading', 'image' => 3,
                'email' => 'contact@orbiqa.example', 'phone' => '0369852147',
                'address' => '789 Orbiqa Street', 'url' => 'https://orbiqa.example',
            ],
        ];
    }

    /**
     * Build the vi/en/ja description block of a category.
     *
     * @param array{0: string, 1: string, 2: string} $vi Name, keyword, description in Vietnamese.
     * @param array{0: string, 1: string, 2: string} $en Name, keyword, description in English.
     * @param array{0: string, 1: string, 2: string} $ja Name, keyword, description in Japanese.
     * @return array<string, array{name: string, keyword: string, description: string}>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private static function texts(array $vi, array $en, array $ja): array
    {
        $texts = [];
        foreach (['vi' => $vi, 'en' => $en, 'ja' => $ja] as $lang => [$name, $keyword, $description]) {
            $texts[$lang] = ['name' => $name, 'keyword' => $keyword, 'description' => $description];
        }

        return $texts;
    }

    /**
     * Build one product row: its hosted photo number and its name in vi/en/ja.
     *
     * @param int    $image Number of the hosted product photo that shows this product.
     * @param string $vi    Product name in Vietnamese.
     * @param string $en    Product name in English.
     * @param string $ja    Product name in Japanese.
     * @return array{image: int, names: array<string, string>}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private static function product(int $image, string $vi, string $en, string $ja): array
    {
        return ['image' => $image, 'names' => ['vi' => $vi, 'en' => $en, 'ja' => $ja]];
    }
}
