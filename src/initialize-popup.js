import {inject, customAttribute} from 'aurelia-framework';
import $ from 'jquery';

@customAttribute('initialize-popup')
export class InitializePopupCustomAttribute {
	attached() {
		$('.deleteLink').popup({
			on: 'click',
			position: 'left center'
		});
	}
}