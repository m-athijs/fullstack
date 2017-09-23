import { inject } from 'aurelia-framework';
import { DialogService } from 'aurelia-dialog';
import { DataRepository } from 'services/dataRepository';
import { DeleteFrameworkDialog } from './DeleteFrameworkDialog';

@inject(DataRepository, DialogService)
export class Frameworks {

    constructor(dataRepository, dialogService) {
        this.dataRepository = dataRepository;
        this.dialogService = dialogService;
    }

    deleteFramework(frameworkToDelete) {
        this.dialogService.open({ 
            viewModel: DeleteFrameworkDialog, 
            model: frameworkToDelete, 
            keyboard: true, 
            lock: true }).whenClosed(response => {
                if (!response.wasCancelled) {
                    this.dataRepository.deleteFramework(frameworkToDelete.id).then(() => {
                        this.frameworks = this.frameworks.filter((framework) => {
                            return framework.id != frameworkToDelete.id;
                        })
                    });
                }
        });
    }

    activate() {
        this.dataRepository.getFrameworks().then(frameworks => {
            this.frameworks = frameworks;
        });
    }

    // async activate() {
    //     let frameworks = await this.dataRepository.getFrameworks();
    //     this.frameworks = await frameworks;
    // }

    hidePopup() {
        $('.deleteLink').popup('hide');
    }

}
