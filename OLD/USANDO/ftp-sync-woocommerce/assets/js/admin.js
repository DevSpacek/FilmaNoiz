/**
 * Scripts para a interface administrativa
 */
jQuery(document).ready(function ($) {
	// Teste de conexão FTP
	$("#ftp-sync-test-connection").on("click", function () {
		var $button = $(this);
		var $result = $("#ftp-sync-test-result");

		$button.prop("disabled", true).text("Testando...");
		$result.hide();

		$.ajax({
			url: ftpSyncData.ajaxUrl,
			type: "POST",
			data: {
				action: "ftp_sync_test_connection",
				security: ftpSyncData.nonce,
			},
			success: function (response) {
				if (response.success) {
					$result.removeClass("error").text(response.data).show();
				} else {
					$result.addClass("error").text(response.data).show();
				}
				$button.prop("disabled", false).text("Testar Conexão FTP");
			},
			error: function () {
				$result.addClass("error").text("Erro de conexão").show();
				$button.prop("disabled", false).text("Testar Conexão FTP");
			},
		});
	});

	// Sincronização manual
	$("#ftp-sync-manual-sync").on("click", function () {
		var $button = $(this);
		var $result = $("#ftp-sync-manual-result");

		$button.prop("disabled", true).text("Sincronizando...");
		$result.hide();

		$.ajax({
			url: ftpSyncData.ajaxUrl,
			type: "POST",
			data: {
				action: "ftp_sync_manual_sync",
				security: ftpSyncData.nonce,
			},
			success: function (response) {
				if (response.success) {
					$result.removeClass("error").text(response.data).show();
				} else {
					$result.addClass("error").text(response.data).show();
				}
				$button.prop("disabled", false).text("Sincronizar Agora");
			},
			error: function () {
				$result.addClass("error").text("Erro de conexão").show();
				$button.prop("disabled", false).text("Sincronizar Agora");
			},
		});
	});
});
