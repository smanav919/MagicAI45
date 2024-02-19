$(document).ready(function () {
	"use strict";

	var addonFilter = "All";
	var strFilter = "";

	function updateList() {
		$('.extension').each((index, element) => {
			console.log(typeof $(element).attr('data-installed'));
			if (addonFilter == 'All') {
				$(element).removeClass('hidden');
			} else if (addonFilter == 'Installed') {
				if ($(element).attr('data-installed') == "1") {
					$(element).removeClass('hidden');
				} else {
					$(element).addClass('hidden');
				}
			} else if (addonFilter == 'Free') {
				if ($(element).attr('data-price') == "0") {
					$(element).removeClass('hidden');
				} else {
					$(element).addClass('hidden');
				}
			} else if (addonFilter == 'Paid') {
				if ($(element).attr('data-price') != "0") {
					$(element).removeClass('hidden');
				} else {
					$(element).addClass('hidden');
				}
			}

			if (!$(element).attr('data-name').toLowerCase().includes(strFilter)) {
				$(element).addClass('hidden');
			}
		})
	}

	$(".addons_filter").on("click", function () {
		$('.addons_filter').removeClass('active');
		$(this).addClass('active');
		var filter = $(this).attr("data-filter");
		addonFilter = filter;
		updateList();
	});

	$("#search_str").on('keydown', function (e) {
		if (e.key == 'Enter') {
			strFilter = $("#search_str").val();
			updateList();
		}
	})

	$("#btn_confirm_method").on('click', function () {

		let formData = new FormData();

		$.ajax({
			type: "post",
			url: "/dashboard/admin/marketplace/buy/" + extension.slug,
			data: formData,
			contentType: false,
			processData: false,
			success: function (data) {
				window.location.href = data.url;
			},
			error: function (data) {
			}
		});
	})

	$(".btn_install").on('click', function () {
		$(this).find('.svg_loading').removeClass('hidden');
		$(this).find('.svg_install').addClass('hidden');

		let formData = new FormData();

		let btn = $(this);

		$.ajax({
			type: "post",
			url: "/install-extension/" + extension.slug,
			data: formData,
			contentType: false,
			processData: false,
			success: function (data) {
				btn.prev().removeClass('hidden');
				btn.addClass('hidden');
				toastr.success('Successfully installed');
				window.location.reload();
			},
			error: function (data) {
				btn.find('.svg_loading').addClass('hidden');
				btn.find('.svg_install').removeClass('hidden');
			}
		});

	})

	const accordionItems = document.querySelectorAll('.custom-accordion-item');

	$('.custom-accordion-header').on('click', function () {

		const accordionItem = $($(this).closest('.custom-accordion-item'));

		const accordionIcon = $(accordionItem.find('.custom-accordion-icon'));

		const accordionBody = $(accordionItem.find('.custom-accordion-body'));

		if (accordionItem.hasClass('active')) {
			accordionIcon.removeClass('rotate-180');
			accordionItem.removeClass('active');
			accordionBody.animate({
				height: 0
			}, 200, 'linear', function () {
				accordionBody.hide();
			});
		} else {
			accordionIcon.addClass('rotate-180');
			accordionItem.addClass('active');
			accordionBody.css('height', '0px');
			accordionBody.css('display', 'block');
			accordionBody.css('overflow', 'hidden');
			// accordionBody.removeClass('hidden');
			accordionBody.animate({
				height: '100%'
			}, 200, 'linear', function () {
				// accordionBody.hide();
			});
		}
	});


});

