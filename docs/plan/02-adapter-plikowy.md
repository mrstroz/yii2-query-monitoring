# E1. Adapter plikowy

**Cel:** domyślny adapter zapisujący paczki do pliku JSON Lines z rotacją i wspólną blokadą.

**Koniec etapu:** aplikacja bez wskazanego adaptera zapisuje paczki do `runtime/logs`, osiem równoległych procesów nie psuje pliku podczas rotacji, a niedostępny plik nie przerywa żądania.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-13** Klasa `FileAdapter` zapisująca paczkę jako wiersz JSON pod nieblokującą blokadą
      Spec: [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · ADR: [0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) · Zależy od: YQM-2
      Gotowe, gdy: `write()` dopisuje wiersz równy `toJson()` z `\n` i zwraca `true`. Przy blokadzie `<path>.lock` trzymanej drugim uchwytem w tym samym procesie zwraca `false` bez wyjątku i bez zmiany pliku. Brakujący katalog powstaje. Ścieżka, której rodzic jest zwykłym plikiem, oraz zapis krótszy od całego wiersza dają wyjątek z `send()` bez ostrzeżenia PHP i bez JSON paczki w komunikacie, a po krótkim zapisie plik ma rozmiar sprzed zapisu.
      Część wymagań spec sprawdza się lekturą kodu, patrz „Uwagi testowe”.

- [x] (=) **YQM-14** Rotacja po rozmiarze pod tą samą blokadą
      Spec: [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · ADR: [0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) · Zależy od: YQM-13
      Gotowe, gdy: rozmiar równy `maxSize` po zapisie nie powoduje rotacji. Przekroczenie przenosi całą bieżącą zawartość do `.1`, przesuwa kopie najwyżej do `.<maxFiles>`, usuwa poprzednią `.<maxFiles>` i nigdy nie tworzy `.<maxFiles + 1>`. Paczka większa niż `maxSize` zapisana jako pierwsza od razu trafia do `.1`, a następny zapis do nowego pliku. Niedająca się zastąpić `.1` powoduje wyjątek bez ostrzeżenia PHP.
      Układ testu dla niedającej się zastąpić `.1` jest w „Uwagach testowych”.

- [x] (=) **YQM-15** Adapter plikowy jako domyślny w komponencie, z kluczem `file`
      Spec: [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) · [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · Zależy od: YQM-14
      Gotowe, gdy: aplikacja testowa bez `adapter` zapisuje paczkę w `<@runtime>/logs/query-monitoring.jsonl`, a samo `file.path` z aliasem nadpisuje ścieżkę i zachowuje pozostałe wartości początkowe. Pusty `path`, `maxSize: 0`, `maxFiles: 0`, nieznany klucz lub nieznany alias wyłączają pakiet przed podmianą `Command`, z jednym `Yii::error`. Własny adapter z błędnym, nieużywanym `file` nadal dostaje paczkę.
      Zmienia zachowanie z E0: `testNullAdapterSendsNothingWithOneError` w `tests/Integration/QueryMonitorComponentTest.php`, komunikat w `QueryMonitor::resolveAdapter()` i opcja `adapter` jako wymagana w `README.md` (`ReadmeExampleTest`) przestają obowiązywać. README opisuje domyślny plik i klucz `file`. Zależy od YQM-14, bo domyślny adapter bez rotacji rósłby bez ograniczeń.

- [x] (^) **YQM-16** Uruchamianie wielu skryptów PHP naraz, ze wspólną barierą startu
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska) · Zależy od: YQM-12
      Gotowe, gdy: runner startuje N procesów PHP z podanym skryptem i autoloadem pakietu, czeka na zgłoszenie gotowości każdego procesu i zwalnia wspólną barierę. Zbiera stdout, stderr i kod wyjścia każdego, a po wspólnym limicie czasu zabija wszystkie nadal działające procesy. Własny test pokazuje, że przedziały pracy 8 procesów się nakładają, oraz sprawdza timeout, także przy procesie, który nie zgłasza gotowości.
      `AppRunner` uruchamia jeden proces aplikacji naraz, a YQM-18 woła adapter bezpośrednio, bez aplikacji Yii. Infrastruktura testowa ma osobne zadanie, jak YQM-12.

- [x] (=) **YQM-17** Ochrona aplikacji z adapterem plikowym w osobnym procesie
      Spec: [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · [01 §6](../spec/01-zbieranie-danych.md#6-ochrona-aplikacji) · Zależy od: YQM-15
      Gotowe, gdy: żądanie przy blokadzie trzymanej przez proces testu ma tę samą odpowiedź co z działającym plikiem i nie zostawia ani paczki, ani `Yii::error`, a katalog bez prawa zapisu daje jeden `Yii::error` z kontekstem `send` i tę samą odpowiedź bez ostrzeżenia PHP.
      Brak drugiej próby w shutdown sprawdza YQM-9 dla każdego adaptera.

- [x] (=) **YQM-18** Osiem równoległych procesów z rotacją bez utraty wiersza poza zajętą blokadą
      Spec: [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · ADR: [0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) · Zależy od: YQM-14, YQM-16
      Gotowe, gdy: 8 procesów za barierą zaczyna od nieistniejącego wspólnego katalogu i wysyła paczki z unikalnym `id` przez `write()` z przerwą rzędu milisekund, przy `maxSize` wymuszającym rotacje i `maxFiles` większym niż liczba wywołań `write()`, więc żadna kopia nie jest usuwana. W pliku bieżącym i kopiach o numerycznych rozszerzeniach każdy wiersz jest poprawnym JSON; zbiór `id` odpowiada dokładnie wywołaniom zwracającym `true`, a `id` wywołań zwracających `false` nie występują. Powstają co najmniej 2 kopie, nie ma `.<maxFiles>` ani `.<maxFiles + 1>`, procesy kończą się bez błędów, a liczba wierszy plus wyników `false` równa się liczbie wywołań.
      Sonda ADR 0005: czy `.lock` chroni rotację. Sama integralność nie wykryje nadpisania `.1` przy dwóch równoległych rotacjach, stąd rozliczenie przez `write()`.

## Uwagi

Kolejność pracy: YQM-13, YQM-14, YQM-16, YQM-18, YQM-15, YQM-17. Sonda z YQM-18 idzie przed podpięciem adaptera do komponentu.

Obowiązkowe scenariusze odbioru są w: rotacja przy 8 procesach, YQM-18. Zajęta blokada, YQM-13 i YQM-17. Katalog bez prawa zapisu, YQM-17. Zapis bez wskazanego adaptera, YQM-15.

E1 nie dodaje kroku CI. Nowe testy są częścią `composer test` i poza YQM-15 i YQM-17 nie potrzebują bazy. Pliki testów leżą w `sys_get_temp_dir()` kontenera, nie w katalogu `/app` montowanym z hosta.

Wskazówki implementacyjne: katalog tworzy `FileHelper::createDirectory()`, który znosi równoległe utworzenie (YQM-13). Rozmiar sprzed zapisu daje `fstat` na uchwycie po wzięciu blokady, nie `ftell` w trybie dopisywania. `ftruncate` działa na uchwycie w tym trybie (YQM-13). Błędy klucza i aliasu `file` są zgłaszane jako `InvalidConfigException`, bo tylko jego treść Guard zapisuje w logu (YQM-15).

## Uwagi testowe

- **Wspólne.** Każdy test pracuje w osobnym katalogu z `sys_get_temp_dir()`.
- **YQM-13, zajęta blokada.** Drugi `flock` w tym samym procesie zwraca `false` z `wouldBlock`, co sprawdzono w obrazie 8.1, więc wystarczy test Unit.
- **YQM-13, nieudany zapis.** `path` jest symlinkiem na `/dev/full`: `fwrite` zwraca `false` albo `0`, a `.lock` powstaje obok symlinku.
- **YQM-13, krótki zapis (0 < n < długość).** Osobny proces uruchomiony przez `sh -c 'trap "" XFSZ; ulimit -f N; exec php …'`. Działa także pod rootem. Po zapisie rozmiar pliku wraca do stanu sprzed zapisu.
- **YQM-13, lektura kodu.** Zapis jednym `fwrite` i odróżnienie `wouldBlock` od innego błędu `flock` sprawdza się lekturą kodu. Nie znaleziono deterministycznego sposobu wywołania innego błędu `flock` w obsługiwanym środowisku.
- **YQM-14, `.1` nie do zastąpienia.** `maxFiles: 1` i `.1` jako niepusty katalog: `rename` daje błąd także pod rootem. Przy `maxFiles > 1` katalog przesunąłby się do `.2` bez błędu.
- **YQM-15, wartości początkowe.** Scenariusz odczytuje ustawienia utworzonego adaptera. Aplikacja testowa dostaje osobny `@runtime` w katalogu tymczasowym na przebieg, a runner odczytuje wynik przed posprzątaniem katalogu.
- **YQM-17, brak prawa zapisu.** `chmod 0555` w procesie nie-root, bo tak działają kontener i CI. Wariant z rodzicem będącym zwykłym plikiem działa też pod rootem.
- **YQM-18, tempo wysyłki.** Bez przerwy między paczkami 8 procesów zapisało 57 z 4000, więc rotacja prawie nie zachodzi. Kryterium nie wymaga ani jednej zajętej blokady, bo test byłby niestabilny. Przy `maxFiles` rzędu tysięcy przesuwanie kopii wydłuża blokadę i zwiększa liczbę `false`, ale nie psuje rozliczenia. Wolny test nie jest więc błędem.
