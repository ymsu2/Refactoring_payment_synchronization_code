<?php

/**
 * Это фрагмент класса SyncCommand для запуска синхронизации платежей между Альфа-Банком и МойСклад.
 * Фрагмент из реального проекта, с которым Вам придется работать.
 *
 * Класс ah - Обертка над массивом для удобной работы с ним.
 * PaymentsIn - Входящий платеж МойСклад (пример данных во вложении)
 * InvoiceOut - Счет покупателю в МойСклад (пример данных во вложении)
 * MoyskladApp - Клиент для доступа к API МойСклад
 */

/**
 * В компанию обратился клиент со следующей проблемой:
 *
 * 200 входящих платежей у клиента привязываются к одному и тому же счету, хотя у каждого входящего платежа
 * должен быть свой индивидуальный счет к которому он должен быть привязан.
 *
 * В аудите платежей видно, что все они были созданы в одно и то же время нашей интеграцией.
 * Платежи действительно имеют разное назначение и содержат корректный номер счета.
 *
 * Пример назначения платежа из кейса:
 * "Оплата по сч/ф 1020 от 19.02.2025 по договору № Б\Н от 16.12.2024 за
 * Закупка поломоечных машин ТР ЮГ в т.ч. НДС 40.487,50"
 */

/**
 * Задача:
 *
 * 1. Выяснить, по какой причине произошла некорректная привязка платежей к счету.
 * 2. Внести изменения в код, чтобы кейс больше не повторился.
 * 3. Сделать рефакторинг метода, учучшив его читаемость и понятность.
 */


/*
Основные изменения:

1. Поиск лучшего соответствия:
   Добавлена система приоритетов: сначала проверяется точное совпадение номера счета, затем суммы и даты.
   Поиск всех возможных совпадений вместо прерывания после первого.

2. Точный поиск номера счета:
     Использование регулярного выражения с границами слова (\b...\b), чтобы избежать частичных совпадений.

3. Обновление оплаченной суммы:
     Явное обновление поля payedSum в счете для корректного отслеживания остатка.

 Рефакторинг:
     Разделение на мелкие методы с понятными названиями.
     Упрощение условий.
     Удаление мутаций через &$variables.
     Использование стрелочных функций для фильтрации.

 Обработка обновлений:
     Отдельный метод для отправки данных в API.
     Проверка уникальности обновлений перед отправкой.

Эти изменения гарантируют, что платежи будут привязываться к правильным счетам даже при наличии нескольких счетов с одинаковыми параметрами организации и агента.
*/


class SyncCommand {

    protected function attachToInvoiceOut(ah $paymentsIn, MoyskladApp $msApp)
    {
        $attributes = $this->user->get('settings.' . AttributeModel::TABLE_NAME, new ah());
        $isAttachedToInvoiceAttr = $attributes->get('paymentin.isAttachedToInvoice')->getAll();

        $msApi = $msApp->getJsonApi();
        $invoicesOut = $this->fetchUnpaidInvoices($msApi);
        
        $updatePayment = [];
        $updateInvoiceOut = [];

        $paymentsIn->each(function ($payment) use (
            $invoicesOut,
            &$updatePayment,
            &$updateInvoiceOut,
            $isAttachedToInvoiceAttr
        ) {
            if (!$this->isPaymentValid($payment)) return;

            $invoiceMatch = $this->findBestInvoiceMatch($payment, $invoicesOut);
            if (!$invoiceMatch) return;

            $this->prepareUpdates(
                $payment,
                $invoiceMatch,
                $isAttachedToInvoiceAttr,
                $updatePayment,
                $updateInvoiceOut
            );
        });

        $this->sendUpdates($msApi, $updatePayment, $updateInvoiceOut);
    }

    private function fetchUnpaidInvoices(MoyskladJsonApi $msApi): array
    {
        $invoices = $msApi->getEntityRows('invoiceout', ['expand' => 'organizationAccount,agent']);
        return (new ah($invoices))
            ->filter(fn($inv) => (int)$inv['sum'] !== (int)$inv['payedSum'] * 100)
            ->getAll();
    }

    private function isPaymentValid(array $payment): bool
    {
        return !empty($payment['organizationAccount']['meta']['href']) 
            && !empty($payment['paymentPurpose']);
    }

    private function findBestInvoiceMatch(array $payment, array $invoicesOut): ?array
    {
        $paymentAccount = $payment['organizationAccount']['meta']['href'];
        $paymentAgent = $payment['agent']['meta']['href'];
        $paymentOrg = $payment['organization']['meta']['href'];

        $matches = [];
        foreach ($invoicesOut as $invoice) {
            $invoiceAccount = $invoice['organizationAccount']['meta']['href'] ?? '';
            $invoiceAgent = $invoice['agent']['meta']['href'] ?? '';
            $invoiceOrg = $invoice['organization']['meta']['href'] ?? '';

            // Базовые проверки
            if ($invoiceAccount !== $paymentAccount 
                || $invoiceAgent !== $paymentAgent 
                || $invoiceOrg !== $paymentOrg
            ) {
                continue;
            }

            // Приоритет 1: Точное совпадение номера счета в назначении
            $invoiceNumber = $invoice['name'];
            $numberInPurpose = $this->isInvoiceNumberInPurpose($invoiceNumber, $payment['paymentPurpose']);
            if ($numberInPurpose) {
                $matches[] = ['invoice' => $invoice, 'score' => 2];
                continue;
            }

            // Приоритет 2: Совпадение суммы и даты
            $dateMatch = $this->isInvoiceDateInPurpose($invoice['moment'], $payment['paymentPurpose']);
            if ($invoice['sum'] == $payment['sum'] && $dateMatch) {
                $matches[] = ['invoice' => $invoice, 'score' => 1];
            }
        }

        /*
        Сортирует массив $matches по убыванию приоритета (значения ключа score).
        usort — функция для пользовательской сортировки массива.
        $b['score'] <=> $a['score'] — оператор "spaceship" сравнивает score элементов:
        - Возвращает 1, если $b['score'] > $a['score'] (элемент $b идет перед $a).
        - Возвращает -1, если $b['score'] < $a['score'].
        - Возвращает 0, если значения равны.
        Итог сортировки: элементы с наибольшим score оказываются в начале массива.
        */
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']); // Выбираем лучший матч
        /*
         Возвращает счет (invoice) из элемента с наивысшим приоритетом (первого в отсортированном массиве) или null, если совпадений нет.
         Как работает:
         $matches[0] — первый элемент массива после сортировки (с максимальным score).
        ['invoice'] — извлекает данные счета из структуры ['invoice' => ..., 'score' => ...].
        ?? null — оператор объединения с null. Если $matches пуст, возвращает null, чтобы избежать ошибки.
        */
        return $matches[0]['invoice'] ?? null;


    }

    private function isInvoiceNumberInPurpose(string $invoiceNumber, string $purpose): bool
    {
        $pattern = '/\b' . preg_quote($invoiceNumber, '/') . '\b/';
        return (bool)preg_match($pattern, $purpose);
    }

    private function isInvoiceDateInPurpose(string $invoiceDate, string $purpose): bool
    {
        $date = date('d.m.Y', strtotime($invoiceDate));
        return strpos($purpose, $date) !== false;
    }

    private function prepareUpdates(
        array $payment,
        array $invoice,
        array $attribute,
        array &$updatePayment,
        array &$updateInvoiceOut
    ): void {
        $attribute['value'] = true;
        $payment['attributes'] = [$attribute];
        $payment['operations'] = [['meta' => $invoice['meta']]];
        $updatePayment[] = $payment;

        $invoice['payedSum'] += $payment['sum']; // Обновляем оплаченную сумму
        $updateInvoiceOut[] = $invoice;
    }

    private function sendUpdates(MoyskladJsonApi $msApi, array $payments, array $invoices): void
    {
        if (!empty($payments)) {
            $msApi->sendEntity('paymentin', $payments);
        }
        if (!empty($invoices)) {
            $msApi->sendEntity('invoiceout', $invoices);
        }
    }
}

