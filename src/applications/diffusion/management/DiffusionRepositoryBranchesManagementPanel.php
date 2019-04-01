<?php

final class DiffusionRepositoryBranchesManagementPanel
  extends DiffusionRepositoryManagementPanel {

  const PANELKEY = 'branches';

  public function getManagementPanelLabel() {
    return pht('Branches');
  }

  public function getManagementPanelOrder() {
    return 1000;
  }

  public function shouldEnableForRepository(
    PhabricatorRepository $repository) {
    return ($repository->isGit() || $repository->isHg());
  }

  public function getManagementPanelIcon() {
    $repository = $this->getRepository();

    $has_any =
      $repository->getDetail('default-branch') ||
      $repository->getTrackOnlyRules() ||
      $repository->getAutocloseOnlyRules();

    if ($has_any) {
      return 'fa-code-fork';
    } else {
      return 'fa-code-fork grey';
    }
  }

  protected function getEditEngineFieldKeys() {
    return array(
      'defaultBranch',
      'trackOnly',
      'autocloseOnly',
    );
  }

  public function buildManagementPanelCurtain() {
    $repository = $this->getRepository();
    $viewer = $this->getViewer();
    $action_list = $this->newActionList();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $repository,
      PhabricatorPolicyCapability::CAN_EDIT);

    $branches_uri = $this->getEditPageURI();

    $action_list->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-pencil')
        ->setName(pht('Edit Branches'))
        ->setHref($branches_uri)
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    return $this->newCurtainView()
      ->setActionList($action_list);
  }

  public function buildManagementPanelContent() {
    $repository = $this->getRepository();
    $viewer = $this->getViewer();
    $content = array();

    $view = id(new PHUIPropertyListView())
      ->setViewer($viewer);

    $default_branch = nonempty(
      $repository->getDetail('default-branch'),
      phutil_tag('em', array(), $repository->getDefaultBranch()));
    $view->addProperty(pht('Default Branch'), $default_branch);

    $track_only_rules = $repository->getTrackOnlyRules();
    $track_only_rules = implode(', ', $track_only_rules);
    $track_only = nonempty(
      $track_only_rules,
      phutil_tag('em', array(), pht('Track All Branches')));
    $view->addProperty(pht('Track Only'), $track_only);

    $autoclose_rules = $repository->getAutocloseOnlyRules();
    $autoclose_rules = implode(', ', $autoclose_rules);
    $autoclose_only = nonempty(
      $autoclose_rules,
      phutil_tag('em', array(), pht('Autoclose On All Branches')));

    $autoclose_disabled = false;
    if ($repository->getDetail('disable-autoclose')) {
      $autoclose_disabled = true;
      $autoclose_only =
        phutil_tag('em', array(), pht('Autoclose has been disabled'));
    }

    $view->addProperty(pht('Autoclose Only'), $autoclose_only);

    $content[] = $this->newBox(pht('Branches'), $view);

    // Branch Autoclose Table
    if (!$repository->isImporting()) {
      $request = $this->getRequest();
      $pager = id(new PHUIPagerView())
        ->readFromRequest($request);

      $params = array(
        'offset' => $pager->getOffset(),
        'limit' => $pager->getPageSize() + 1,
        'repository' => $repository->getID(),
      );

      $branches = id(new ConduitCall('diffusion.branchquery', $params))
        ->setUser($viewer)
        ->execute();
      $branches = DiffusionRepositoryRef::loadAllFromDictionaries($branches);
      $branches = $pager->sliceResults($branches);
      $can_close_branches = ($repository->isHg());

      $rows = array();
      foreach ($branches as $branch) {
        $branch_name = $branch->getShortName();
        $tracking = $repository->shouldTrackBranch($branch_name);
        $autoclosing = $repository->shouldAutocloseBranch($branch_name);

        $default = $repository->getDefaultBranch();
        $icon = null;
        if ($default == $branch->getShortName()) {
          $icon = id(new PHUIIconView())
            ->setIcon('fa-code-fork');
        }

        $fields = $branch->getRawFields();
        $closed = idx($fields, 'closed');
        if ($closed) {
          $status = pht('Closed');
        } else {
          $status = pht('Open');
        }

        if ($autoclose_disabled) {
          $autoclose_status = pht('Disabled (Repository)');
        } else {
          $autoclose_status = pht('Off');
        }

        $rows[] = array(
          $icon,
          $branch_name,
          $status,
          $tracking ? pht('Tracking') : pht('Off'),
          $autoclosing ? pht('Autoclose On') : $autoclose_status,
        );
      }
      $branch_table = new AphrontTableView($rows);
      $branch_table->setHeaders(
        array(
          '',
          pht('Branch'),
          pht('Status'),
          pht('Track'),
          pht('Autoclose'),
        ));
      $branch_table->setColumnClasses(
        array(
          '',
          'pri',
          'narrow',
          'narrow',
          'wide',
        ));
      $branch_table->setColumnVisibility(
        array(
          true,
          true,
          $can_close_branches,
          true,
          true,
        ));

      $box = $this->newBox(pht('Branch Status'), $branch_table);
      $box->setPager($pager);
      $content[] = $box;
    } else {
      $content[] = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild(pht('Branch status in unavailable while the repository '.
          'is still importing.'));
    }

    return $content;
  }

}
