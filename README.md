# Dolibarr-MassEditor
Als Nebenprodukt einer Sync-Middleware zwischen Dolibarr und Prestashop entstand ein Produkte-Massen-Editor für Dolibarr mit dem im Excel-Stil sehr schnell
Produkte bzw. Artikel bearbeitet werden können: Bezeichnung (Produkt-Name), Verkaufspreis (Netto), Lagerbestand (mit der Berücksichtigung von Multi-Lagern), Online-Shop (ja/nein) und Kategorien hinzufügen/entfernen (natürlich auch mit Multi-Kategorien).

Installation:
1. per FTP ein neues Verzeichnis anlegen: /custom/massedit
2. in das neue Verzeichnis die index.php kopieren
3. über das Dolibarr-Frontend (Einstellungen -> Menüs -> Menü-Editor -> +-Zeichen) einen neuen Menüpunkt anlegen und die URL auf die neue Datei /custom/massedit/index.php zeigen
4. der neue Menüpunkt sollte dann in der oberen Navigationsleiste und in der linken Navigationsleiste bei den Produkten zu sehen sein
