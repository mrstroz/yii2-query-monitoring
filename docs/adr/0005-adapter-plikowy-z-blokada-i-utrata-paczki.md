# ADR-0005: Adapter plikowy JSON Lines z blokadą nieblokującą

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Domyślny adapter, [spec 03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) |

## Kontekst

Wiele procesów PHP-FPM i procesów konsoli zapisuje do jednego pliku, często jako dwóch różnych użytkowników systemu (serwer WWW i konsola). Rotacja przez `rename` w jednym procesie i `fwrite` w drugim bez wspólnej blokady daje zapis do już przeniesionego pliku. `yii\log\FileTarget` blokuje sam plik logu przez `flock`, ale rotuje bez blokady, co jest znanym źródłem utraty wierszy. Główna zasada zabrania blokowania żądania przez monitoring.

## Decyzja

Jedna paczka to jeden wiersz JSON. Zapis i rotacja biorą `flock` z `LOCK_EX | LOCK_NB` na osobnym pliku `.lock`. Gdy blokada jest zajęta, paczka przepada. Plik tylko na lokalnym dysku. Proces, który tworzy katalog, plik danych albo `.lock`, nadaje im tryb z `file.dirMode` i `file.fileMode`, domyślnie zapis dla grupy, a katalog zachowuje odziedziczony bit setgid. Rotacja przesuwa kopie przez `rename` na miejsce starszej, bez osobnego `unlink`, a plik już większy niż `maxSize` jest rotowany przed zapisem i nie dostaje nowego wiersza, gdy rotacja zawiedzie.

## Konsekwencje

**Pozytywne:** żądanie nie czeka na zajętą blokadę. Sam zapis i rotacja nadal zajmują czas procesu, mierzony w teście wydajności. Rotacja i zapis nie mogą się przeplatać. Format czytelny przez `jq` i przez przyszły worker.

**Negatywne:** przy dużym ruchu część paczek przepada bez śladu w pliku. NFS jest poza zakresem, bo `flock` na NFS nie jest wiarygodny. Tryb z zapisem dla grupy działa tylko wtedy, gdy obaj użytkownicy należą do jednej grupy, a pliki ją dziedziczą. Proces bez prawa zapisu do katalogu nie zrotuje pliku: wtedy jego paczki przepadają z wyjątkiem, zamiast powiększać plik bez końca.

**Wymagania:** katalog `runtime/logs` z prawem zapisu dla procesów FPM i konsoli: użytkownik serwera WWW i użytkownik konsoli we wspólnej grupie, katalog tej grupy z bitem setgid (`chmod g+s`), żeby nowe pliki dziedziczyły grupę.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Blokujący `flock` | Proces czeka na monitoring. Sprzeczne z główną zasadą |
| Plik tymczasowy plus `rename`, bez blokady | Działa na NFS, ale daje wiele plików na sekundę i wymaga sprzątania |
| Blokada na samym pliku danych | Po rotacji stary uchwyt wskazuje przeniesiony plik, blokada nie chroni rotacji |
| Tryb plików tylko z `umask` procesu (jak domyślnie w `yii\log\FileTarget`) | Plik utworzony przez serwer WWW z `umask` 022 nie jest zapisywalny dla konsoli i odwrotnie. Paczki drugiego użytkownika przepadają z błędem aż do rotacji |
| `unlink` najstarszej kopii przed przesunięciem | Rotacja przerwana w połowie (np. `rename` cudzej kopii w katalogu ze sticky bit) zostawia przesunięte kopie, a każda następna próba usuwa kolejną z nich. `rename` na miejsce starszej przy powtórzonej porażce niczego więcej nie usuwa |

## Kiedy wrócić do tej decyzji

Gdy pomiar pokaże utratę paczek przez zajętą blokadę powyżej ułamka procenta albo gdy aplikacja musi pisać na współdzielony wolumen.
