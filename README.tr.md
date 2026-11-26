# WooCommerce için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Paymos için resmi WooCommerce ödeme eklentisi. Ödeme sayfasına stablecoin seçeneği ekler, alıcıyı barındırılan Paymos ödeme sayfasına yönlendirir ve WooCommerce siparişini imzalı webhook üzerinden günceller. Alıcı, Paymos projenizde etkin olan token ve ağı seçer. Sipariş tutarı mağazanızın kendi para biriminde iletilir ve kur alıcı ödeme yaptığı anda sabitlenir; böylece hesabınıza geçen tutar faturaladığınız tutarla birebir eşleşir.

Sipariş yaşam döngüsü alıcının sitenize geri dönmesine bağlı değildir. Kapanan bir sekme, biten telefon şarjı veya tarayıcı çökmesi sonucu değiştirmez: webhook ulaşır ve sipariş güncellenir.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Ürün videosu (1:12, 6 dilde altyazı):** [YouTube'da izle](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- WordPress 6.5 veya üzeri (7.0'a kadar test edildi);
- WooCommerce 8.0 veya üzeri (10.9'a kadar test edildi);
- `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 7.4 veya üzeri;
- webhook teslimatı için genel internetten erişilebilen HTTPS mağaza adresi;
- kontrol panelinde ödemeleri alacak projenin açık olduğu bir Paymos hesabı.

Paymos PHP SDK paketin içinde gelir; mağazada Composer gerekmez. High-Performance Order Storage (HPOS) ve Checkout Blocks uyumluluğu beyan edilmiştir.

## Kurulum ve bağlantı

1. En son resmi paketi [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest) üzerinden veya Paymos panelindeki **CMS integration** bölümünden indirin.
2. **Eklentiler → Yeni Ekle → Eklenti Yükle** ekranından yükleyip etkinleştirin.
3. **WooCommerce → Ayarlar → Ödemeler → Paymos** ekranını açın.
4. **Connect Paymos** düğmesine tıklayın; onay için yeni sekmede Paymos açılır.
5. Paymos, panelde o an seçili olan projeyi kullanır. Sandbox ve Live ortamları için mağazanın tek aktif Payment anahtarını yeniden kullanır (yoksa oluşturur) ve tam eşleşen Invoice webhook'unu kullanır ya da bu mağaza adresi için özel bir webhook oluşturur.
6. WooCommerce'e dönün ve Sandbox veya Live modunu seçin.

İndirilen paket tüm üye işyerleri için aynıdır ve içinde hiçbir kimlik bilgisi (API anahtarı, API gizli anahtarı, proje kimliği, webhook gizli anahtarı, OAuth belirteci veya cihaz kodu) bulunmaz. Yalnızca indirmek bağlantı kurmaz; mağazayı etkinleştiren adım 4'teki onaydır.

WooCommerce içinde yapıştırılacak bir anahtar veya proje seçici yoktur. Tek kullanımlık cihaz yetkilendirme yanıtı eklenti türü ve mağaza URL'si ile doğrulanır, otomatik yüklenmeyen (non-autoloaded) bir WordPress seçeneğine AES-256-GCM şifrelemesiyle kaydedilir ve kısa ömürlü OAuth belirteci silinir. Bu noktadan sonra tüm Merchant API çağrıları HMAC ile imzalanır. Kaydedilen sırlar ayarlar HTML'ine geri yazdırılmaz ve elle girilemez — anahtar veya webhook sırrı yenilendiğinde yeniden bağlanmanız yeterlidir.

Sizin yerinize kaydedilen ve ayarlar sayfasında salt okunur gösterilen webhook adresi:

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

## Sipariş durumu akışı

Sipariş verildiğinde fatura oluşturulur, sepet boşaltılır ve alıcı barındırılan ödeme sayfasına yönlendirilir. Yönlendirmeden önce sipariş *Awaiting Paymos payment* notuyla **Beklemede (On hold)** durumuna alınır.

| Gelen olay | Siparişin geçeceği durum |
|---|---|
| `invoice.confirming` | On hold — transfer zincirde ve onay topluyor |
| `invoice.underpaid_waiting` | On hold; sipariş notunda kalan eksik tutar belirtilir |
| `invoice.awaiting_payment` | On hold — sayılan bir transfer zincir yeniden düzenlemesi (reorg) nedeniyle geri alındı |
| `invoice.paid`, `invoice.paid_over` | `payment_complete()` — WooCommerce'in kendi kurallarına göre İşleniyor (Processing) veya tamamen sanal ve indirilebilir ürünlerde Tamamlandı (Completed) olur |
| `invoice.underpaid` | Başarısız (Failed) |
| `invoice.expired`, `invoice.cancelled` | İptal edildi (Cancelled) |

Bu tabloyu iki koruma kuralı güvence altına alır. Ödenmiş bir sipariş gecikmiş veya sırasız gelen bir olayla asla geri alınmaz — eski durum not olarak kaydedilip yok sayılır. Ayrıca tutarı artık sipariş toplamıyla eşleşmeyen bir ödeme olayı gelirse sipariş otomatik tamamlanmak yerine *needs manual review* notuyla **On hold** durumunda tutulur.

Ödeme tamamlandığında işlem özeti (hash) ve blok gezgini bağlantısı sipariş meta verisine yazılır; teşekkür sayfasında, müşteri e-postasında ve sipariş ekranındaki **Paymos Payment** kutusunda (fatura kimliği, tutar anlık görüntüsü ve son olayın kimlik/zaman damgasıyla birlikte) gösterilir.

## Önce Sandbox ortamında test edin

1. **Mode** ayarını Sandbox olarak bırakın, ödeme yöntemini etkinleştirin ve mağazanızda bir test siparişi oluşturun.
2. Bu faturayı Paymos panelinde açın. Sandbox ödemeleri zamanlayıcıyla onaylanmaz — test etmek istediğiniz sonucu (`paid`, `overpaid`, `underpaid`, `cancelled`) seçin; zincirdekiyle aynı yaşam döngüsü olayları tetiklenir.
3. WooCommerce siparişinin geçişlerini ve sipariş notlarındaki olay kaydını izleyin.
4. Akış doğru çalıştığında **Mode** ayarını Live yapın. Bağlantı her iki ortamı kapsadığı için yeniden bağlanmanız gerekmez.

Sandbox transferlerinde zincir üstü kanıt bulunmadığından işlem özeti ve blok gezgini bağlantısı boş kalır; bu beklenen bir durumdur.

## Webhook doğrulama ve mutabakat

Tüm bildirimler `X-Webhook-Signature` (`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, sabit zamanlı karşılaştırma ve gizli anahtar yenileme sırasında geçiş penceresi) ile imzalanır. Her olay eklenti tarafından saklanan kararlı bir `X-Webhook-Id` taşır; tekrar teslimatlar ikinci kez uygulanmadan onaylanır.

Eklenti herhangi bir nihai durum yazmadan önce faturayı Merchant API üzerinden yeniden çeker ve doğrular; böylece sahte veya tekrarlanmış bir gövde tek başına siparişi tamamlayamaz.

On dakikada bir çalışan WP-Cron görevi (`paymos_reconcile_orders`) fatura güvenlik ağı olarak çalışır: hâlâ bekleyen/on-hold durumundaki en fazla 50 ve faturası henüz kesinleşmemiş başarısız/iptal edilmiş en fazla 50 Paymos siparişini API'den sorgulayıp aynı kurallarla işler. Dakikada bir çalışan ayrı bir WP-Cron görevi (`paymos_woocommerce_refunds_reconcile`) ise bekleyen iadeleri eşitler.

## İadeler

Eklenti, Paymos Merchant API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan iki modda yerel iadeleri destekler: `full` (tam fatura iadesi) ve `overpayment` (`paid_over` faturasındaki fazla ödemenin iadesi). Paymos iadeyi yeni bir giden blokzincir işlemi olarak kesinlikle ödenmiş faturada kayıtlı doğrulanmış `first_sender` adresine gönderir — alıcı cüzdan adresi elle girilmez ve rastgele kısmi iade tutarları desteklenmez.

Yöneticiler WooCommerce sipariş düzenleme ekranından iki yolla iade başlatabilir:

- sipariş kalemleri kutusundaki yerel WooCommerce **İade (Refund)** düğmesi (`full` iade talebi gönderir);
- hem `full` hem `overpayment` modlarını destekleyen, doğrulanmış `first_sender` alıcısını ve güncel işlem durumunu gösteren **Paymos Payment** iade paneli (`woocommerce_admin_order_data_after_order_details`).

Blokzincir transferi sürerken WooCommerce yerel bir `WC_Order_Refund` kaydı oluşturmaz. `/wp-json/paymos/v1/webhook` adresindeki imzalı webhook'lar ve 1 dakikalık `paymos_woocommerce_refunds_reconcile` WP-Cron görevi iadeyi Merchant API ile doğrular ve işlem `completed` durumuna ulaştığında WooCommerce iadesini (veya `overpayment` için sipariş notunu) işler. Sandbox modunda nihai iade sonuçları (`completed`, `failed`, `cancelled`) simüle edilebilir.

## Sorun giderme

**Sipariş On hold durumundan çıkmıyor.** Webhook'ların ulaşabilmesi için mağaza genel internetten erişilebilir olmalıdır; HTTP kimlik doğrulaması arkasındaki veya özel ağdaki bir test sitesi bildirim alamaz. Yukarıdaki webhook adresinin yanıt verdiğini doğrulayın, ardından ağ geçidi ayarlarında **Debug logging** seçeneğini açıp **WooCommerce → Durum → Günlükler** ekranını inceleyin.

**Zincirde ödendi ancak sipariş tutarın manuel incelenmesini istiyor.** Fatura oluşturulduktan sonra sipariş toplamı değişmiştir. **Paymos Payment** kutusundaki tutar anlık görüntüsünü güncel sipariş toplamıyla karşılaştırıp elle çözün — eklenti tutar uyuşmazlığında siparişi otomatik tamamlamaz.

**Ödeme sayfasında yöntem görünmüyor.** Etkin ortam tam olarak bağlanmadığında ağ geçidi kendini gizler. Ayarlarda **Connection status** tablosunu kontrol edin; *Missing* gösteren herhangi bir satır o ortamın bağlantısının tamamlanmadığını belirtir.

- [Dokümantasyon](https://paymos.io/tr/docs/cms-woocommerce)
- [Kaynak kod](https://github.com/paymos-labs/woocommerce)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
