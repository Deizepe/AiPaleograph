@php
$containerFooter = !empty($containerNav) ? $containerNav : 'container-fluid';
@endphp

<!-- Footer-->
<footer class="content-footer footer bg-footer-theme">
    <div class="{{ $containerFooter }}">
        <div class="footer-container d-flex align-items-center justify-content-between py-4 flex-md-row flex-column">
            <div class="text-body">
                © <script>
                document.write(new Date().getFullYear())
                </script>, made by <a href="https://github.com/Deizepe" target="_blank" rel="noopener" class="footer-link">Anderson Deizepe</a>
            </div>
            <div class="d-none d-lg-inline-block">
                <a href="mailto:anderson@deizepe.com.br" class="footer-link d-none d-sm-inline-block">Support</a>
            </div>
        </div>
    </div>
</footer>
<!--/ Footer-->