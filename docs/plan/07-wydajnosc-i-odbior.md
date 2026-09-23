# E6. Wydajność i odbiór

**Cel:** pomiar narzutu, korekta limitów i dokumentacja użytkownika pakietu.

**Koniec etapu:** wynik testu z [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności) mieści się w progu, limity w spec mają wartości potwierdzone pomiarem, a `README.md` pakietu opisuje instalację i konfigurację.

**Zależności zewnętrzne:** brak.

## Zadania

Zadania zostaną spisane, gdy E5 się zakończy. Etap rozstrzyga człon 3c otwartej kwestii 3 w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie): rotację plików. Z YQM-30 przechodzą tu dwa obowiązkowe punkty: paczka wypełniona do `maxEntries` i `maxBatchBytes` wpisami z `caller` daje w jednym procesie przyrost `memory_get_peak_usage(true)` poniżej 2 MB z [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności), mierzony bez stanowiska wydajnościowego; oraz `QueryMonitor::$maxEntries` i `$maxBatchBytes` biorą wartości początkowe ze stałych `QueryCollector::DEFAULT_MAX_ENTRIES` i `DEFAULT_MAX_BATCH_BYTES`, jak `maxQueryLength` bierze `SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH`, a `QueryMonitorDefaultsTest` sprawdza obie wartości.
