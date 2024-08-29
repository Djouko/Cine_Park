<div class="tab-pane fade" id="v-pills-reservation" role="tabpanel" aria-labelledby="v-pills-reservation-tab">
    <div class="fp_dashboard_body">
        <h3>Reservations</h3>
        <div class="fp_dashboard_order">
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                        <tr class="t_header">
                            <th>No</th>
                            <th>Reseration Id</th>
                            <th>Date/Time</th>
                            <th>Person</th>
                            <th>Status</th>

                        </tr>
                        @foreach ($reservations as $reservation)
                        <tr>
                            <td>
                                <h5>{{ ++$loop->index }}</h5>
                            </td>
                            <td>
                                {{ $reservation->reservation_id }}
                            </td>
                            <td>
                                {{ $reservation->date }} | {{ $reservation->time }}
                            </td>
                            <td>
                                {{ $reservation->persons }}
                            </td>
                            <td>
                                @if ($reservation->status === 'pending')
                                <span class="active">Attente</span>
                                @elseif ($reservation->status === 'approve')
                                <span class="active">Traitement</span>
                                @elseif ($reservation->status === 'complete')
                                <span class="complete">Complete</span>
                                @elseif ($reservation->status === 'cancel')
                                <span class="cancel">Cancel</span>
                                @endif
                            </td>

                        </tr>
                        @endforeach

                    </tbody>
                </table>
            </div>
        </div>
        @foreach ($orders as $order)
        <div class="fp__invoice invoice_details_{{ $order->id }}">
            <a class="go_back d-print-none"><i class="fas fa-long-arrow-alt-left"></i> go back</a>
            <div class="fp__track_order d-print-none">
                <ul>

                    @if ($order->order_status === 'declined')

                    <li class="
                    declined_status
                    {{ in_array($order->order_status, ['declined']) ? 'active' : '' }}
                    ">Reservation refusee</li>
                    @else
                    <li class="
                    {{ in_array($order->order_status, ['pending', 'in_process', 'delivered', 'declined']) ? 'active' : '' }}
                    ">Reservation en attente</li>
                    <li class="
                    {{ in_array($order->order_status, ['in_process', 'delivered', 'declined']) ? 'active' : '' }}
                    ">Reservation en traitement</li>
                    <li class="
                    {{ in_array($order->order_status, ['delivered']) ? 'active' : '' }}
                    ">Reservation acceptee</li>
                    @endif
                    {{-- <li>on decliend</li> --}}
                </ul>
            </div>
            <div class="fp__invoice_header">
                <div class="header_address">
                    <h4>Billet pour</h4>
                    <p>{{ @$order->userAddress->first_name }}</p>
                    <p>{{ $order->address }}</p>
                    <p>{{ @$order->userAddress->phone }}</p>
                    <p>{{ @$order->userAddress->email }}</p>

                </div>
                <div class="header_address" style="width: 50%">
                    <p><b style="width: 140px">Billet no: </b><span>{{ @$order->invoice_id }}</span></p>
                    <p><b style="width: 140px">Payment Status: </b><span>{{ @$order->payment_status }}</span></p>
                    <p><b style="width: 140px">Payment Method: </b><span>{{ @$order->payment_method }}</span></p>
                    <p><b style="width: 140px">Transaction Id: </b><span>{{ @$order->transaction_id }}</span></p>



                    <p><b style="width: 140px">date:</b> <span>{{ date('d-m-Y', strtotime($order->created_at)) }}</span></p>
                </div>
            </div>
            <div class="fp__invoice_body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <tbody>
                            <tr class="border_none">
                                <th class="sl_no">SL</th>
                                <th class="package">item description</th>
                                <th class="price">Price</th>
                                <th class="qnty">NombrePlaces</th>
                                <th class="total">Total</th>
                            </tr>

                            @foreach ($order->orderItems as $item)
                            @php
                                $size = json_decode($item->product_size);
                                $options = json_decode($item->product_option);

                                $qty = $item->qty;
                                $untiPrice = $item->unit_price;
                                $sizePrice = $size->price ?? 0;

                                $optionPrice = 0;
                                foreach ($options as $optionItem) {
                                    $optionPrice += $optionItem->price;
                                }

                                $productTotal = ($untiPrice + $sizePrice + $optionPrice) * $qty;
                            @endphp
                            <tr>
                                <td class="sl_no">{{ ++$loop->index }}</td>
                                <td class="package">
                                    <p>{{ $item->product_name }}</p>
                                    <span class="size">{{ @$size->name }} - {{ @$size->price ? currencyPosition(@$size->price) : ''}}</span>
                                    @foreach ($options as $option)
                                    <span class="coca_cola">{{ @$option->name }} - {{ @$option->price ? currencyPosition(@$option->price) : '' }}</span>
                                    @endforeach
                                </td>
                                <td class="price">
                                    <b>{{ currencyPosition($item->unit_price) }}</b>
                                </td>
                                <td class="qnty">
                                    <b>{{ $item->qty }}</b>
                                </td>
                                <td class="total">
                                    <b>{{ currencyPosition($productTotal) }}</b>
                                </td>
                            </tr>
                            @endforeach

                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="package" colspan="3">
                                    <b>sub total</b>
                                </td>
                                <td class="qnty">
                                    <b>-</b>
                                </td>
                                <td class="total">
                                    <b>{{ currencyPosition($order->subtotal) }}</b>
                                </td>
                            </tr>
                            <tr>
                                <td class="package coupon" colspan="3">
                                    <b>(-) Coupon de reduction</b>
                                </td>
                                <td class="qnty">
                                    <b></b>
                                </td>
                                <td class="total coupon">
                                    <b>{{ currencyPosition($order->discount) }}</b>
                                </td>
                            </tr>
                            <tr>
                                <td class="package coast" colspan="3">
                                    <b>(+) Frais de livraison</b>
                                </td>
                                <td class="qnty">
                                    <b></b>
                                </td>
                                <td class="total coast">
                                    <b>{{ currencyPosition($order->delivery_charge) }}</b>
                                </td>
                            </tr>
                            <tr>
                                <td class="package" colspan="3">
                                    <b>Total payé</b>
                                </td>
                                <td class="qnty">
                                    <b></b>
                                </td>
                                <td class="total">
                                    <b>{{ currencyPosition($order->grand_total) }}</b>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <a class="print_btn common_btn d-print-none" href="javascript:;" onclick="printInvoice('{{ $order->id }}')"><i class="far fa-print "></i> print
                PDF</a>

        </div>
        @endforeach
    </div>
</div>

@push('scripts')
    <script>
        function viewInvoice(id){
            $(".fp_dashboard_order").fadeOut();
            $(".invoice_details_"+id).fadeIn();
        }

        function printInvoice(id) {
            let printContents = $('.invoice_details_'+id).html();

            let printWindow = window.open('', '', 'width=600,height=600');
            printWindow.document.open();
            printWindow.document.write('<html>');
            printWindow.document.write('<link rel="stylesheet" href="{{ asset("frontend/css/bootstrap.min.css") }}">');
            printWindow.document.write('<link rel="stylesheet" href="{{ asset("frontend/css/style.css") }}');

            printWindow.document.write('<body>');
            printWindow.document.write(printContents);
            printWindow.document.write('</body></html>');
            printWindow.document.close();

            printWindow.print();
            printWindow.close();
        }
    </script>
@endpush
