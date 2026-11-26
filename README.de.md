# Paymos für WooCommerce

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles WooCommerce-Zahlungs-Gateway für Paymos. Fügt Ihrem Checkout eine Stablecoin-Zahlungsmethode hinzu, leitet Käufer auf die gehostete Paymos-Checkout-Seite weiter und aktualisiert die WooCommerce-Bestellung über einen signierten Webhook. Der Käufer wählt Token und Netzwerk aus den in Ihrem Paymos-Projekt aktivierten Optionen. Der Bestellbetrag wird in Ihrer Shop-Währung übergeben und der Kurs im Zahlungsmoment fixiert — gutgeschrieben wird genau der in Rechnung gestellte Betrag.

Nichts im Bestellablauf hängt davon ab, dass der Käufer in den Shop zurückkehrt. Geschlossener Tab, leerer Akku oder Browser-Absturz führen zum selben Ergebnis: Der Webhook trifft ein und aktualisiert die Bestellung.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- WordPress 6.5 oder neuer (getestet bis 7.0);
- WooCommerce 8.0 oder neuer (getestet bis 10.9);
- PHP 7.4 oder neuer mit den Erweiterungen `curl`, `hash`, `json` und `openssl`;
- eine öffentlich erreichbare HTTPS-Shop-URL für die Webhook-Zustellung;
- ein Paymos-Konto mit geöffnetem Zielprojekt im Dashboard.

Das Paymos PHP SDK ist direkt im Paket enthalten — Composer wird im Shop nicht benötigt. Kompatibilität mit High-Performance Order Storage (HPOS) und Checkout Blocks ist deklariert.

## Installation und Verbindung

1. Laden Sie das aktuelle offizielle Paket unter [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest) oder im Bereich **CMS integration** Ihres Paymos-Dashboards herunter.
2. Laden Sie das Paket unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** hoch und aktivieren Sie es.
3. Öffnen Sie **WooCommerce → Einstellungen → Zahlungen → Paymos**.
4. Klicken Sie auf **Connect Paymos**. Ein neuer Tab öffnet Paymos zur Freigabe.
5. Paymos verwendet das aktuell im Dashboard ausgewählte Projekt. Für Sandbox und Live wird der einzige aktive Payment-Key wiederverwendet (oder erstellt, falls noch keiner existiert) und ein exakt passender Invoice-Webhook wiederverwendet oder ein eigener Webhook für diese Shop-URL angelegt.
6. Kehren Sie zu WooCommerce zurück und wählen Sie den Modus Sandbox oder Live.

Das Paket ist für alle Händler identisch und enthält keinerlei Zugangsdaten — keinen API-Key, kein API-Secret, keine Project-ID, kein Webhook-Secret, kein OAuth-Token und keinen Device-Code. Erst die Freigabe in Schritt 4 provisioniert den Shop.

In WooCommerce müssen weder Schlüssel eingefügt noch ein Projekt ausgewählt werden. Die einmalige Device-Authorization-Antwort wird gegen Plugin-Typ und Shop-URL geprüft, als AES-256-GCM-verschlüsselte WordPress-Option ohne Autoload gespeichert und das kurzlebige OAuth-Token verworfen. Alle weiteren Aufrufe der Merchant API sind HMAC-signiert. Gespeicherte Werte werden nie wieder im Einstellungs-HTML ausgegeben und können nicht manuell eingetragen werden — verbinden Sie nach einer Key- oder Webhook-Secret-Rotation einfach neu.

Automatisch registrierte Webhook-URL (in den Einstellungen schreibgeschützt angezeigt):

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

## Bestellstatus-Ablauf

Beim Absenden der Bestellung erstellt das Plugin die Rechnung, leert den Warenkorb und leitet zum gehosteten Checkout weiter. Zuvor wird die Bestellung auf **In Wartestellung (On hold)** mit der Notiz *Awaiting Paymos payment* gesetzt, damit kein unklarer Zwischenzustand entsteht.

| Eingehendes Ereignis | Bestellstatus in WooCommerce |
|---|---|
| `invoice.confirming` | On hold — die Transaktion ist on-chain und sammelt Bestätigungen |
| `invoice.underpaid_waiting` | On hold; die Notiz nennt den noch offenen Restbetrag |
| `invoice.awaiting_payment` | On hold — eine gezählte Transaktion wurde durch ein Reorg zurückgerollt |
| `invoice.paid`, `invoice.paid_over` | `payment_complete()` — WooCommerce setzt entsprechend eigener Regeln auf In Bearbeitung (Processing) oder Abgeschlossen (Completed) bei rein virtuellen, herunterladbaren Bestellungen |
| `invoice.underpaid` | Fehlgeschlagen (Failed) |
| `invoice.expired`, `invoice.cancelled` | Storniert (Cancelled) |

Zwei Schutzmechanismen sichern diese Zuordnung ab: Eine bezahlte Bestellung wird durch ein verspätetes oder unsortiertes Ereignis niemals herabgestuft — der veraltete Status wird als Notiz vermerkt und verworfen. Und wenn der bezahlte Betrag nicht mehr zur Bestellsumme passt, bleibt die Bestellung auf **On hold** mit *needs manual review*, damit nachträglich geänderte Bestellungen nicht unbemerkt abgeschlossen werden.

Bei Abschluss werden Transaktions-Hash und Explorer-Link in den Bestell-Metadaten gespeichert und auf der Dankeseite, in der Kunden-E-Mail sowie im Feld **Paymos Payment** der Bestellansicht angezeigt — samt Invoice-ID, Betrags-Snapshot sowie letztem Event mit ID und Zeitstempel.

## Zuerst in der Sandbox testen

1. Lassen Sie **Mode** auf Sandbox, aktivieren Sie die Zahlungsmethode und geben Sie eine Testbestellung in Ihrem Shop auf.
2. Öffnen Sie diese Rechnung im Paymos-Dashboard. Sandbox-Zahlungen bestätigen nicht per Timer — wählen Sie das gewünschte Ergebnis (`paid`, `overpaid`, `underpaid`, `cancelled`), und es werden dieselben Lifecycle-Events wie on-chain ausgelöst.
3. Prüfen Sie die Statuswechsel und Bestellnotizen in WooCommerce.
4. Schalten Sie **Mode** auf Live um, sobald der Ablauf stimmt. Die Verbindung deckt beide Umgebungen ab — erneutes Verbinden ist nicht nötig.

Sandbox-Transfers haben keinen On-Chain-Nachweis; leere Felder für Transaktions-Hash und Explorer-Link sind dort normal.

## Webhooks und Abgleich (Reconciler)

Zustellungen sind mit `X-Webhook-Signature` signiert (`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, Timing-Safe-Vergleich mit Übergangsfrist bei Secret-Rotation). Jedes Ereignis trägt eine stabile `X-Webhook-Id`, die das Plugin speichert, sodass erneute Zustellungen quittiert und nicht doppelt verarbeitet werden.

Vor dem Schreiben eines Endstatus ruft das Plugin die Rechnung erneut aus der Merchant API ab und prüft sie gegen — ein gefälschter oder wiederholter Webhook-Body allein kann keine Bestellung abschließen.

Ein WP-Cron-Job (`paymos_reconcile_orders`) läuft alle zehn Minuten als Sicherheitsnetz: Er prüft bis zu 50 offene/wartende sowie bis zu 50 fehlgeschlagene/stornierte Paymos-Bestellungen ohne endgültigen Rechnungsstatus über die API mit denselben Schutzregeln wie der Webhook. Ein separater minütlicher Job (`paymos_woocommerce_refunds_reconcile`) gleicht offene Rückerstattungen ab.

## Rückerstattungen

Das Plugin unterstützt native Rückerstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei serverseitig berechneten Modi: `full` (vollständige Rechnungsrückerstattung) und `overpayment` (Erstattung des Überschusses bei `paid_over`). Paymos sendet die Rückerstattung als neue ausgehende Blockchain-Transaktion strikt an die verifizierte `first_sender`-Adresse der bezahlten Rechnung — eine manuelle Zieladresse ist weder erforderlich noch möglich, und beliebige Teilbeträge werden nicht unterstützt.

Auf der Bestellbearbeitungsseite in WooCommerce können Administratoren eine Rückerstattung auf zwei Wegen auslösen:

- über die native WooCommerce-Schaltfläche **Rückerstattung (Refund)** im Positionsbereich (sendet eine `full`-Anfrage);
- über das Rückerstattungs-Panel **Paymos Payment** (`woocommerce_admin_order_data_after_order_details`), das `full` und `overpayment` unterstützt und den verifizierten `first_sender`-Empfänger sowie den aktuellen Status anzeigt.

WooCommerce bucht kein lokales `WC_Order_Refund`, solange die Blockchain-Transaktion noch läuft. Signierte Webhooks an `/wp-json/paymos/v1/webhook` und der minütliche WP-Cron-Job `paymos_woocommerce_refunds_reconcile` prüfen den Status über die Merchant API und erfassen die Rückerstattung (bzw. Bestellnotiz bei `overpayment`) erst bei Erreichen von `completed`. Im Sandbox-Modus lassen sich Endzustände (`completed`, `failed`, `cancelled`) simulieren.

## Fehlerbehebung

**Die Bestellung bleibt dauerhaft auf On hold.** Der Shop muss öffentlich aus dem Internet erreichbar sein, damit Webhooks ankommen; eine Staging-Umgebung hinter HTTP-Auth oder in einem privaten Netz erhält keine Webhooks. Prüfen Sie die Erreichbarkeit der obigen Webhook-URL und schauen Sie mit aktiviertem **Debug logging** unter **WooCommerce → Status → Protokolle** nach.

**On-chain bezahlt, aber die Bestellung verlangt manuelle Betragsprüfung.** Die Bestellsumme wurde nach Erstellung der Rechnung geändert. Vergleichen Sie den Betrags-Snapshot im Feld **Paymos Payment** mit dem aktuellen Bestellbetrag und klären Sie den Fall manuell — nicht abgleichbare Bestellungen schließt das Plugin nicht automatisch ab.

**Das Gateway erscheint nicht im Checkout.** Es blendet sich aus, wenn die aktive Umgebung nicht vollständig verbunden ist. Prüfen Sie in den Gateway-Einstellungen die Tabelle **Connection status**; ein Eintrag mit *Missing* zeigt, dass die Verbindung für diese Umgebung unvollständig ist.

- [Dokumentation](https://paymos.io/de/docs/cms-woocommerce)
- [Quellcode](https://github.com/paymos-labs/woocommerce)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
