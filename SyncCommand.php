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

class SyncCommand {

    /**
     * Метод отвечает за связываение платежа и счета покупателю. Это необходимо для того,
     * чтобы менеджеры понимали, что данный счет уже оплачен.
     *
     * @param ah $paymentsIn
     * @param MoyskladApp $msApp
     * @return void|null
     */
    protected function attachToInvoiceOut(ah $paymentsIn, MoyskladApp $msApp)
    {
        $attributes = $this->user->get('settings.' . AttributeModel::TABLE_NAME, new ah());
        $isAttachedToInvoiceAttr = $attributes->get('paymentin.isAttachedToInvoice')->getAll();

        $msApi = $msApp->getJsonApi();
        $invoicesOut = $msApi->getEntityRows('invoiceout', [
            'expand' => 'organizationAccount, agent'
        ]);

        $invoicesOut = (new ah($invoicesOut))->filter(function ($item) {
            return (int)$item['sum'] !== (int)$item['payedSum'] * 100;
        })->getAll();

        $updatePayment = [];
        $updateInvoiceOut = [];
        $paymentsIn->each(function($payment) use (
            $invoicesOut,
            &$updatePayment,
            &$updateInvoiceOut,
            &$isAttachedToInvoiceAttr
        ) {

           if (empty($payment['organizationAccount']['meta']['href']) || empty($payment['paymentPurpose'])) {
                return;
            }

            foreach ($invoicesOut as &$invoiceOut) {
                $arr = new ah($invoiceOut);
                if (empty($arr['organizationAccount']['meta']['href'])) {
                    continue;
                }

                $notEqualAgent = !TextHelper::isEqual($arr['agent']['meta']['href'], $payment['agent']['meta']['href']);
                $notEqualAccount = !TextHelper::isEqual($arr['organizationAccount']['meta']['href'], $payment['organizationAccount']['meta']['href']);
                $notEqualOrganization = !TextHelper::isEqual($arr['organization']['meta']['href'], $payment['organization']['meta']['href']);

                if ($notEqualAgent || $notEqualAccount || $notEqualOrganization) {
                    continue;
                }

                // найти номер счета в назначении платежа
                $attachedByPurpose = false;
                if (strpos($payment['paymentPurpose'], $arr['name']) !== false
                    || ((int)$arr['name'] !== 0 && strpos($payment['paymentPurpose'], (string)(int)$arr['name']) !== false)) {
                    $attachedByPurpose = self::invoiceNumberInPurpose($arr['name'], $payment['paymentPurpose']);
                }

                // найти дату выставления счета в назначении платежа
                if (!$attachedByPurpose && $arr['sum'] == $payment['sum']) {
                    $prepareDate = date('d.m.Y', strtotime($arr['moment']));
                    $attachedByPurpose = strpos($payment['paymentPurpose'], $prepareDate) !== false;
                }

                if (!$attachedByPurpose && $arr['sum'] != $payment['sum']) {
                    continue;
                }

                $isAttachedToInvoiceAttr['value'] = true;
                $payment['attributes'] = [$isAttachedToInvoiceAttr];
                $payment['operations'] = [['meta' => $invoiceOut['meta']]];
                $updatePayment[] = $payment;

                $invoiceOut['payments'] = [['meta' => $payment['meta']]];
                $updateInvoiceOut[] = $invoiceOut;

/*
Код прерывает поиск после первого частично подходящего счета (return; внутри цикла). 
Если есть несколько счетов с одинаковыми agent, organizationAccount и organization, но разными номерами, платежи будут привязываться к первому найденному
*/
                return; // return внутри цикла
            }
        });

        if (!empty($updatePayment)) {
            $msApi->sendEntity('paymentin', $updatePayment);
        }

        if (!empty($updateInvoiceOut)) {
            $msApi->sendEntity('invoiceout', $updateInvoiceOut);
        }
    }

    /**
     * @param $invoiceName
     * @param $paymentPurpose
     *
     * @return bool
     */

/*
invoiceNumberInPurpose ищет номер счета в назначении платежа, заменяя все нецифровые символы на пробелы и разбивая на части. 
Если номер счета состоит из цифр, которые могут встречаться в других частях назначения, это может приводить к ложным срабатываниям. 
Например, если номер счета "1020", а в назначении есть "1020" как часть другого числа или текста.

*/


    protected static function invoiceNumberInPurpose($invoiceName, $paymentPurpose): bool
    {
        $prepareStr = preg_replace('/\D/', ' ', $paymentPurpose);
        $prepareStr = preg_replace('/\s+/', ' ', $prepareStr);

        $ppAr = explode(' ', $prepareStr);
        foreach ($ppAr as $piece) {
            if ($piece == $invoiceName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Остальные методы класса. Для решения задачи они не нужны.
     */
}